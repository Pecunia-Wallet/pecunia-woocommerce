<?php
declare(strict_types=1);

namespace Pecunia\Models;

class ItemList
{

	public array $items;

	public int $total;
	public int $remaining;

	public function __construct(array $items = array(), int $total = 0, int $remaining = 0)
	{
		$this->items = $items;
		$this->total = $total;
		$this->remaining = $remaining;
	}
}
