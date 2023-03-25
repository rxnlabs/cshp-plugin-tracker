<?php

/**
 * Creates a markdown document based on the parsed documentation
 *
 * @author Peter-Christoph Haider <peter.haider@zeyon.net>
 * @package Apidoc
 * @version 1.00 (2014-04-04)
 * @license GNU Lesser Public License
 * @see https://gist.github.com/dapepe/9956717
 */
class TextTable {
	/** @var int The source path */
	public $maxlen = 50;
	/** @var array The source path */
	private $data = array();
	/** @var array The source path */
	private $header = array();
	/** @var array The source path */
	private $len = array();
	/** @var array The source path */
	private $align = array(
		'name' => 'L',
		'type' => 'C'
	);

	/**
	 * @param array $header  The header array [key => label, ...]
	 * @param array $content Content
	 * @param array $align   Alignment optios [key => L|R|C, ...]
	 */
	public function __construct($header=null, $content=array(), $align=false) {
		if ($header) {
			$this->header = $header;
		} elseif ($content) {
			foreach ($content[0] as $key => $value)
				$this->header[$key] = $key;
		}

		foreach ($this->header as $key => $label) {
			$this->len[$key] = mb_strlen($label);
		}

		if (is_array($align))
			$this->setAlgin($align);

		$this->addData($content);
	}

	/**
	 * Overwrite the alignment array
	 *
	 * @param array $align   Alignment optios [key => L|R|C, ...]
	 */
	public function setAlgin($align) {
		$this->align = $align;
	}

	/**
	 * Add data to the table
	 *
	 * @param array $content Content
	 */
	public function addData($content) {
		foreach ($content as &$row) {
			foreach ($this->header as $key => $value) {
				if (!isset($row[$key])) {
					$row[$key] = '-';
				} elseif (mb_strlen($row[$key]) > $this->maxlen) {
					$this->len[$key] = $this->maxlen;
					$row[$key] = substr($row[$key], 0, $this->maxlen-3).'...';
				} elseif (mb_strlen($row[$key]) > $this->len[$key]) {
					$this->len[$key] = mb_strlen($row[$key]);
				}
			}
		}

		$this->data = $this->data + $content;
		return $this;
	}

	/**
	 * Add a delimiter
	 *
	 * @return string
	 */
	private function renderDelimiter() {
		$res = '|';
		foreach ($this->len as $key => $l)
			$res .= (isset($this->align[$key]) && ($this->align[$key] == 'C' || $this->align[$key] == 'L') ? ':' : ' ')
			        .str_repeat('-', $l)
			        .(isset($this->align[$key]) && ($this->align[$key] == 'C' || $this->align[$key] == 'R') ? ':' : ' ')
			        .'|';
		return $res."\r\n";
	}

	/**
	 * Render a single row
	 *
	 * @param  array $row
	 * @return string
	 */
	private function renderRow($row) {
		$res = '|';
		foreach ($this->len as $key => $l) {
			$res .= ' '.$row[$key].($l > mb_strlen($row[$key]) ? str_repeat(' ', $l - mb_strlen($row[$key])) : '').' |';
		}

		return $res."\r\n";
	}

	/**
	 * Render the table
	 *
	 * @param  array  $content Additional table content
	 * @return string
	 */
	public function render($content=array()) {
		$this->addData($content);

		$res = $this->renderRow($this->header)
		       .$this->renderDelimiter();
		foreach ($this->data as $row)
			$res .= $this->renderRow($row);

		return $res;
	}
}