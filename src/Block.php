<?php


namespace Riclep\Storyblok;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Riclep\Storyblok\Exceptions\UnableToRenderException;
use Riclep\Storyblok\Fields\Asset;
use Riclep\Storyblok\Fields\Image;
use Riclep\Storyblok\Fields\MultiAsset;
use Riclep\Storyblok\Fields\RichText;
use Riclep\Storyblok\Fields\Table;
use Riclep\Storyblok\Traits\CssClasses;
use Riclep\Storyblok\Traits\HasChildClasses;
use Riclep\Storyblok\Traits\HasMeta;
use Storyblok\ApiException;

class Block implements \IteratorAggregate
{
	use CssClasses;
	use HasChildClasses;
	use HasMeta;

	/**
	 * @var bool resolve UUID relations automatically
	 */
	public $_autoResolveRelations = false;

	/**
	 * @var array list of field names containing relations to resolve
	 */
	public $_resolveRelations = [];

	/**
	 * @var bool Remove unresolved relations such as those that 404
	 */
	public $_filterRelations = true;

	/**
	 * @var array the path of nested components
	 */
	public $_componentPath = [];

	/**
	 * @var array the path of nested components
	 */
	protected $_casts = [];

	/**
	 * @var Collection all the fields for the Block
	 */
	private $_fields;

	/**
	 * @var Page|Block reference to the parent Block or Page
	 */
	private $_parent;

	/**
	 * Takes the Block’s content and a reference to the parent
	 * @param $content
	 * @param $parent
	 */
	public function __construct($content, $parent = null)
	{
		$this->_parent = $parent;
		$this->preprocess($content);

		if ($parent) {
			$this->_componentPath = array_merge($parent->_componentPath, [Str::lower($this->meta()['component'])]);
		}

		$this->processFields();

		// run automatic traits - methods matching initTraitClassName()
		foreach (class_uses_recursive($this) as $trait) {
			if (method_exists($this, $method = 'init' . class_basename($trait))) {
				$this->{$method}();
			}
		}
	}

	/**
	 * Returns the containing every field of content
	 *
	 * @return Collection
	 */
	public function content() {
		return $this->_fields;
	}

	/**
	 * Checks if the fields contain the specified key
	 *
	 * @param $key
	 * @return bool
	 */
	public function has($key) {
		return $this->_fields->has($key);
	}

	/**
	 * Returns the parent Block
	 *
	 * @return Block
	 */
	public function parent() {
		return $this->_parent;
	}

	/**
	 * Returns the page this Block belongs to
	 *
	 * @return Block
	 */
	public function page() {
		if ($this->parent() instanceof Page) {
			return $this->parent();
		}

		return $this->parent()->page();
	}

	/**
	 * Returns the first matching view, passing it the fields
	 *
	 * @return View
	 * @throws UnableToRenderException
	 */
	public function render() {
		try {
			return view()->first($this->views(), ['block' => $this]);
		} catch (\Exception $exception) {
			throw new UnableToRenderException('None of the views in the given array exist.', $this);
		}
	}

	/**
	 * Returns an array of possible views for the current Block based on
	 * it’s $componentPath match the component prefixed by each of it’s
	 * ancestors in turn, starting with the closest, for example:
	 *
	 * $componentPath = ['page', 'parent', 'child', 'this_block'];
	 *
	 * Becomes a list of possible views like so:
	 * ['child.this_block', 'parent.this_block', 'page.this_block'];
	 *
	 * Override this method with your custom implementation for
	 * ultimate control
	 *
	 * @return array
	 */
	public function views() {
		$compontentPath = $this->_componentPath;
		array_pop($compontentPath);

		$views = array_map(function($path) {
			return config('storyblok.view_path') . 'blocks.' . $path . '.' . $this->component();
		}, $compontentPath);

		$views = array_reverse($views);

		$views[] = config('storyblok.view_path') . 'blocks.' . $this->component();

		return $views;
	}

	/**
	 * Returns a component X generations previous
	 *
	 * @param $generation int
	 * @return mixed
	 */
	public function ancestorComponentName($generation)
	{
		return $this->_componentPath[count($this->_componentPath) - ($generation + 1)];
	}

	/**
	 * Checks if the current component is a child of another
	 *
	 * @param $parent string
	 * @return bool
	 */
	public function isChildOf($parent)
	{
		return $this->_componentPath[count($this->_componentPath) - 2] === $parent;
	}

	/**
	 * Checks if the component is an ancestor of another
	 *
	 * @param $parent string
	 * @return bool
	 */
	public function isAncestorOf($parent)
	{
		return in_array($parent, $this->parent()->_componentPath);
	}

	/**
	 * Returns the current Block’s component name from Storyblok
	 *
	 * @return string
	 */
	public function component() {
		return $this->_meta['component'];
	}


	/**
	 * Returns the HTML comment required for making this Block clickable in
	 * Storyblok’s visual editor. Don’t forget to set comments to true in
	 * your Vue.js app configuration.
	 *
	 * @return string
	 */
	public function editorLink() {
		if (array_key_exists('_editable', $this->_meta) && config('storyblok.edit_mode')) {
			return $this->_meta['_editable'];
		}

		return '';
	}


	/**
	 * Magic accessor to pull content from the _fields collection. Works just like
	 * Laravel’s model accessors. Matches public methods with the follow naming
	 * convention getSomeFieldAttribute() - called via $block->some_field
	 *
	 * @param $key
	 * @return null|string
	 */
	public function __get($key) {
		$accessor = 'get' . Str::studly($key) . 'Attribute';

		if (method_exists($this, $accessor)) {
			return $this->$accessor();
		}

		if ($this->has($key)) {
			return $this->_fields[$key];
		}

		return null;
	}

	/**
	 * Loops over every field to get the ball rolling
	 */
	private function processFields() {
		$this->_fields->transform(function ($field, $key) {
			return $this->getFieldType($field, $key);
		});
	}

	/**
	 * Converts fields into Field Classes based on various properties of their content
	 *
	 * @param $field
	 * @param $key
	 * @return array|Collection|mixed|Asset|Image|MultiAsset|RichText|Table
	 * @throws \Storyblok\ApiException
	 */
	private function getFieldType($field, $key) {
		$factory = new FieldFactory();
		return $factory->build($this, $field, $key);
	}

	/**
	 * Storyblok returns fields and other meta content at the same level so
	 * let’s do a little tidying up first
	 *
	 * @param $content
	 */
	private function preprocess($content) {
		$this->_fields = collect(array_diff_key($content, array_flip(['_editable', '_uid', 'component'])));

		// remove non-content keys
		$this->_meta = array_intersect_key($content, array_flip(['_editable', '_uid', 'component']));
	}

	/**
	 * Let’s up loop over the fields in Blade without needing to
	 * delve deep into the content collection
	 *
	 * @return \Traversable
	 */
	public function getIterator() {
		return $this->_fields;
	}

	public function getRelation(RequestStory $request, $relation) {
		try {
			$response = $request->get($relation);

			$class = $this->getChildClassName('Block', $response['content']['component']);
			$relationClass = new $class($response['content'], $this);

			$relationClass->addMeta([
				'name' => $response['name'],
				'published_at' => $response['published_at'],
				'full_slug' => $response['full_slug'],
			]);

			return $relationClass;
		} catch (ApiException $e) {
			return null;
		}
	}

	public function getCasts() {
		return $this->_casts;
	}
}