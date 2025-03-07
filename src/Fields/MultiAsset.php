<?php


namespace Riclep\Storyblok\Fields;


use Illuminate\Support\Str;
use Riclep\Storyblok\Field;
use Riclep\Storyblok\Traits\HasChildClasses;

class MultiAsset extends Field implements \ArrayAccess, \Iterator, \Countable
{
	use HasChildClasses;

	/**
	 * @var int used for iterating over the array of assets
	 */
	private $iteratorIndex = 0;

	/**
	 * Returns a comma delimited list of filenames
	 *
	 * @return string
	 */
	public function __toString()
	{
		return $this->content->map(function ($item) {
			if (is_object($item) && $item->has('filename')) {
				return $item->filename;
			}
		})->filter()->implode(',');
	}

	/**
	 * Attempts to determine the types of assets that have been linked
	 */
	public function init() {
		if ($this->hasFiles()) {
			$this->content = collect($this->content())->transform(function ($file) {
				if (Str::endsWith($file['filename'], ['.jpg', '.jpeg', '.png', '.gif', '.webp'])) {
					if ($class = $this->getChildClassName('Field', $this->block()->component() . 'Image')) {
						return new $class($file, $this->block());
					}

					return new Image($file, $this->block());
				}

				if ($class = $this->getChildClassName('Field', $this->block()->component() . 'Asset')) {
					return new $class($file, $this->block());
				}

				return new Asset($file, $this->block());
			});
		}
	}

	/**
	 * Checks if files are uploaded
	 *
	 * @return bool
	 */
	public function hasFiles() {
		return (bool) $this->content();
	}

	/*
	 * Methods for ArrayAccess Trait - allows us to dig straight down to the content collection when calling a key on the Object
	 * */
	public function offsetSet($offset, $value) {
		if (is_null($offset)) {
			$this->content[] = $value;
		} else {
			$this->content[$offset] = $value;
		}
	}

	public function offsetExists($offset) {
		return isset($this->content[$offset]);
	}

	public function offsetUnset($offset) {
		unset($this->content[$offset]);
	}

	public function offsetGet($offset) {
		return isset($this->content[$offset]) ? $this->content[$offset] : null;
	}

	/*
	 * Methods for Iterator trait allowing us to foreach over a collection of
	 * Blocks and return their content. This makes accessing child content
	 * in Blade much cleaner
	 * */
	public function current()
	{
		return $this->content[$this->iteratorIndex];
	}

	public function next()
	{
		$this->iteratorIndex++;
	}

	public function rewind()
	{
		$this->iteratorIndex = 0;
	}

	public function key()
	{
		return $this->iteratorIndex;
	}

	public function valid()
	{
		return isset($this->content[$this->iteratorIndex]);
	}

	/*
	 * Countable trait
	 * */
	public function count()
	{
		return $this->content->count();
	}
}