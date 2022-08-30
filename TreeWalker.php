<?php declare(strict_types=1);
/*
 * Copyright (C) Apis Networks, Inc - All Rights Reserved.
 *
 * Unauthorized copying of this file, via any medium, is
 * strictly prohibited without consent. Any dissemination of
 * material herein is prohibited.
 *
 * For licensing inquiries email <licensing@apisnetworks.com>
 *
 * Written by Matt Saladna <matt@apisnetworks.com>, August 2022
 */


namespace Module\Support\Webapps\App\Type\Nextcloud;

use PhpParser\ConstExprEvaluationException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node;

/**
 * Class AST
 *
 * @package Module\Support\Webapps\App\Type\Wordpress
 *
 */
class TreeWalker
{
	use \apnscpFunctionInterceptorTrait;
	use \ContextableTrait;

	const STORAGE_VAR = 'CONFIG';

	/**
	 * @var \PhpParser\Node\Stmt[]
	 */
	protected $ast;
	/**
	 * @var \PhpParser\NodeTraverser
	 */
	protected $traverser;

	/** @var string filename */
	protected $file;

	/**
	 * Util_AST constructor.
	 *
	 * @param string $file
	 * @throws \ArgumentError
	 * @throws \PhpParser\Error
	 */
	protected function __construct(string $file)
	{
		if (!$this->file_exists($file)) {
			throw new \ArgumentError(\ArgumentFormatter::format("Target file %s does not exist", [$file]));
		}
		$code = $this->file_get_file_contents($this->file = $file);
		$parser = (new \PhpParser\ParserFactory)->create(\PhpParser\ParserFactory::PREFER_PHP7);
		$this->ast = $parser->parse($code);
	}

	/**
	 * Replace matching define() rules
	 *
	 * @param string $var search variable
	 * @param mixed  $new replacement value
	 * @return self
	 */
	public function replace(string $var, $new): self
	{
		return $this->walkReplace($var, $new, false);
	}

	/**
	 * Set matching define() statements or add
	 *
	 * @param string $var search variable
	 * @param mixed  $new replacement value
	 * @return self
	 */
	public function set(string $var, $new): self
	{
		return $this->walkReplace($var, $new, true);
	}

	/**
	 * Get value from AST
	 *
	 * @param string $var
	 * @param        $default
	 * @return mixed|null
	 */
	public function get(string $var, $default = null)
	{
		/** @var Node $found */
		$found = null;

		foreach ($this->ast as &$stmt) {
			if ($stmt->expr->var->name === self::STORAGE_VAR) {
				break;
			}
		}

		if ($stmt->expr->var->name !== self::STORAGE_VAR) {
			return $default;
		}

		foreach ($stmt->expr->expr->items as &$item) {
			if ($item->key->value === $var) {
				$found = $item;
				break;
			}
		}

		if (null === $found) {
			return $default;
		}

		try {
			return (new ConstExprEvaluator)->evaluateSilently($found->value);
		} catch (ConstExprEvaluationException $expr) {
			return (new \PhpParser\PrettyPrinter\Standard())->prettyPrint(
				[$found]
			);
		}
	}

	/**
	 * Walk tree applying substitution rules
	 *
	 * @param string $var
	 * @param        $new
	 * @param bool   $append append if not found
	 * @return $this
	 */
	private function walkReplace(string $var, $new, bool $append = false): self
	{
		foreach ($this->ast as &$stmt) {
			if ($stmt->expr->var->name === self::STORAGE_VAR) {
				break;
			}
		}
		if ($stmt->expr->var->name !== self::STORAGE_VAR && !$append) {
			return $this;
		}
		if ($stmt->expr->var->name !== self::STORAGE_VAR) {
			fatal("Missing %s", self::STORAGE_VAR);
		}

		foreach ($stmt->expr->expr->items as &$item) {
			if ($item->key->value === $var) {
				break;
			}
		}
		if ((!$item || $item->key->value !== $var) && !$append) {
			return $this;
		}

		if ($item && $item->key->value === $var) {
			$item->value = $this->inferType($new);
		} else {
			$stmt->expr->expr->items[] = new Node\Expr\ArrayItem(
				$this->inferType($new),
				new Node\Scalar\String_($var)
			);
		}

		return $this;
	}

	private function inferType($val): \PhpParser\NodeAbstract
	{
		return \PhpParser\BuilderHelpers::normalizeValue($val);
	}

	/**
	 * Generate configuration
	 *
	 * @return string
	 */
	public function __toString()
	{
		return (new \PhpParser\PrettyPrinter\Standard())->prettyPrint(
			$this->ast
		);
	}

	/**
	 * Save configuration
	 *
	 * @return bool
	 */
	public function save(): bool
	{
		return $this->file_put_file_contents($this->file, '<?php' . "\n" . (string)$this);
	}


}