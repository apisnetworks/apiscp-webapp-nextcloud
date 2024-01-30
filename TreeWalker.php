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
 * @package Module\Support\Webapps\App\Type\Nextcloud
 *
 */
class TreeWalker extends \Module\Support\Php\TreeWalker
{
	const STORAGE_VAR = 'CONFIG';

	public function get(string $var, mixed $default = null): mixed
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
	 * @{@inheritDoc}
	 */
	public function replace(string $var, mixed $new): self
	{
		return $this->walkReplace($var, $new, false);
	}

	/**
	 * @{@inheritDoc}
	 */
	public function set(string $var, mixed $new): self
	{
		return $this->walkReplace($var, $new, true);
	}

	/**
	 * Walk tree applying substitution rules
	 *
	 * @param string $var
	 * @param mixed  $new
	 * @param bool   $append append if not found
	 * @return $this
	 */
	private function walkReplace(string $var, mixed $new, bool $append = false): self
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
}