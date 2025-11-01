<?php

namespace Websyspro\Core\Commons;

class Collection
{
  public function __construct(
    private array $items = []
  ){}

  public function add(
    mixed $item
  ): Collection {
    $this->items[] = $item;
    return $this;
  }

  public function merge(
    Collection|array $collectionOrArray 
  ): Collection {
    if($collectionOrArray instanceof Collection){
      $this->items = array_merge(
        $this->items, $collectionOrArray->all()
      );
    } else {
      $this->items = array_merge(
        $this->items, $collectionOrArray
      );
    }

    return $this;
  }

  public function mapper(
    callable|object $callableOrObject
  ): Collection {
    if(is_callable( $callableOrObject ) === false){
      // TODO:: mapper
      return new Collection();
    }

    $this->items = Utils::mapper(
      $this->items, $callableOrObject
    );

    return $this;
  }

  public function where(
    callable $callable
  ): Collection {
    return new Collection(Utils::where($this->items, $callable));
  }

  public function find(
    callable $callable
  ): mixed {
    return Utils::find($this->items, $callable);
  }

  public function reduce(
    mixed $curremt,
    callable $callable
  ): mixed {
    return Utils::reduce($curremt, $this->items, $callable);
  }

  public function slice(
    int $start,
    int|null $lenght = null
  ): Collection {
    $this->items = array_slice($this->items, $start, $lenght);
    return $this;
  }
  
  public function chunk(
    int $length
  ): Collection {
    $this->items = Utils::chunk($this->items, $length);
    return $this;
  }  

  public function join(
    string $join = ""
  ): string {
    return implode($join, $this->items);
  }

  public function joinWithComma(
  ): string {
    return $this->Join(", ");
  }

  public function joinWithSpace(
  ): string {
    return $this->Join(" ");
  }

  public function joinNotSpace(
  ): string {
    return $this->Join("");
  }

  public function count(
  ): int {
    return sizeof($this->items);
  }

  public function exist(
  ): bool {
    return sizeof($this->items) !== 0;
  }
  
  public function sum(
    callable $callable
  ): float {
    return array_sum(Utils::mapper( $this->items, $callable ));
  }

  public function first(    
  ): mixed {
    return reset($this->items);
  }

  public function last(    
  ): mixed {
    return end($this->items);
  }  

  public function all(
  ): array {
    return $this->items;
  }
}