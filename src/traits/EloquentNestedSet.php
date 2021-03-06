<?php

namespace Linhnc\EloquentNestedSet\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Nested Set Model - hierarchies tree
 */
trait EloquentNestedSet
{
    /**
     * Get custom parent_id column name
     *
     * @return string
     */
    public static function parentIdColumn(): string
    {
        return defined(static::class . '::PARENT_ID') ? static::PARENT_ID : 'parent_id';
    }

    /**
     * Get custom right column name
     *
     * @return string
     */
    public static function rightColumn(): string
    {
        return defined(static::class . '::RIGHT') ? static::RIGHT : 'right';
    }

    /**
     * Get custom left column name
     *
     * @return string
     */
    public static function leftColumn(): string
    {
        return defined(static::class . '::LEFT') ? static::LEFT : 'left';
    }

    /**
     * Get custom root's id value
     *
     * @return int
     */
    public static function rootId(): int
    {
        return defined(static::class . '::ROOT_ID') ? static::ROOT_ID : 1;
    }

    /**
     * Get table name
     *
     * @return string
     */
    public static function tableName(): string
    {
        return (new static)->getTable();
    }

    /**
     * get primary column name
     *
     * @return string
     */
    public static function primaryColumn(): string
    {
        return (new static)->getKeyName();
    }

    /**
     * @return mixed
     */
    public static function rootNode(): mixed
    {
        return static::withoutGlobalScope('ignore_root')->find(static::rootId());
    }

    /**
     * Update tree when CRUD
     *
     * @return void
     * @throws Throwable
     */
    public static function bootEloquentNestedSet(): void
    {
        // Ignore root node in global scope
        static::addGlobalScope('ignore_root', function (Builder $builder) {
            $builder->where(static::tableName() . '.' . static::primaryColumn(), '<>', static::rootId());
        });
        static::saving(function (Model $model) {
            if (empty($model->{static::parentIdColumn()})) {
                $model->{static::parentIdColumn()} = static::rootId();
            }
        });
        static::creating(function (Model $model) {
            $model->updateTreeOnCreating();
        });
        static::updating(function (Model $model) {
            $model->updateTreeOnUpdating();
        });
        static::deleted(function (Model $model) {
            $model->updateTreeOnDeleted();
        });
    }

    /**
     * Scope a query to find ancestors.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeAncestors($query)
    {
        return $query
            ->where(static::leftColumn(), '<', $this->{static::leftColumn()})
            ->where(static::rightColumn(), '>', $this->{static::rightColumn()});
    }

    /**
     * Scope a query to find descendants.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeDescendants($query)
    {
        return $query
            ->where(static::leftColumn(), '>', $this->{static::leftColumn()})
            ->where(static::rightColumn(), '<', $this->{static::rightColumn()});
    }

    /**
     * L???y t???t c??? c??c entity cha, s???p x???p theo th??? t??? entity cha g???n nh???t ?????u ti??n.
     *
     * C??c entity cha trong 1 c??y s??? c??
     * - left nh??? h??n left c???a entity hi???n t???i
     * - right l???n h??n right c???a entity hi???n t???i
     */
    public function getAncestors()
    {
        return $this->ancestors()->orderBy(static::leftColumn(), 'DESC')->get();
    }

    /**
     * L???y t???t c??? c??c entity con
     *
     * C??c entity con trong 1 c??y s??? c??
     * - left l???n h??n left c???a entity hi???n t???i
     * - right nh??? h??n right c???a entity hi???n t???i
     */
    public function getDescendants()
    {
        return $this->descendants()->get();
    }

    /**
     * The parent entity to which the current entity belongs
     *
     * @return BelongsTo
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, static::parentIdColumn());
    }

    /**
     * The children entity belongs to the current entity
     *
     * @return HasMany
     */
    public function children(): HasMany
    {
        return $this->hasMany(static::class, static::parentIdColumn());
    }

    /**
     * Initial a query builder to interact with tree
     *
     * @param int|null $parentId
     * @return Builder
     */
    public static function tree(int $parentId = null): Builder
    {
        $parentId = $parentId ?: static::rootId();
        $tableName = static::tableName();

        return static::query()
            ->selectRaw("$tableName.*")
            ->join("$tableName as parent", function ($join) use ($tableName, $parentId) {
                $join
                    ->on("$tableName." . static::leftColumn(), '>', 'parent.' . static::leftColumn())
                    ->on("$tableName." . static::leftColumn(), '<', 'parent.' . static::rightColumn())
                    ->where('parent.' . static::primaryColumn(), '=', $parentId);
            })
            ->orderBy("$tableName." . static::leftColumn());
    }

    /**
     * Build a nested tree
     *
     * @param Collection $nodes
     * @return Collection
     */
    public static function buildNestedTree(Collection $nodes): Collection
    {
        $tree = collect([]);
        $groupNodes = $nodes->groupBy(static::parentIdColumn());
        $tree->push(...$groupNodes->get(static::rootId()) ?? []);

        $getChildrenFunc = function ($tree) use (&$getChildrenFunc, $groupNodes) {
            foreach ($tree as $item) {
                $item->children = $groupNodes->get($item->id) ?: [];
                $getChildrenFunc($item->children);
            }
        };

        $getChildrenFunc($tree);
        return $tree;
    }

    /**
     * Get all nodes in nested array
     */
    public static function getTree(): Collection
    {
        $nodes = static::tree()->get();

        return static::buildNestedTree($nodes);
    }

    /**
     * Get all nodes order by parent-children relationship in flat array
     *
     * @return Collection
     */
    public static function getFlatTree(): Collection
    {
        return static::tree()->get();
    }

    /**
     * Get all parent in nested array
     *
     * @return Collection
     */
    public function getAncestorsTree(): Collection
    {
        return static::buildNestedTree($this->ancestors()->get());
    }

    /**
     * Get all descendants in nested array
     *
     * @return Collection
     */
    public function getDescendantsTree(): Collection
    {
        return static::buildNestedTree($this->descendants()->get());
    }

    /**
     * @return int
     */
    public function getWidth(): int
    {
        return $this->{static::rightColumn()} - $this->{static::leftColumn()} + 1;
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function updateTreeOnCreating(): void
    {
        try {
            DB::beginTransaction();
            $parent = static::withoutGlobalScope('ignore_root')->find($this->{static::parentIdColumn()});
            $parentRgt = $parent->{static::rightColumn()};

            // Node m???i s??? ???????c th??m v??o sau (b??n ph???i) c??c nodes c??ng cha
            $this->{static::leftColumn()} = $parentRgt;
            $this->{static::rightColumn()} = $parentRgt + 1;
            $width = $this->getWidth();

            // C???p nh???t c??c node b??n ph???i c???a node cha
            static::withoutGlobalScope('ignore_root')
                ->where(static::rightColumn(), '>=', $parentRgt)
                ->update([static::rightColumn() => DB::raw(static::rightColumn() . " + $width")]);

            static::query()
                ->where(static::leftColumn(), '>', $parentRgt)
                ->update([static::leftColumn() => DB::raw(static::leftColumn() . " + $width")]);

            DB::commit();
        } catch (Throwable $th) {
            DB::rollBack();
            throw $th;
        }
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function updateTreeOnUpdating(): void
    {
        $oldParentId = (int)$this->getOriginal(static::parentIdColumn());
        $newParentId = (int)$this->{static::parentIdColumn()};

        if ($oldParentId === $newParentId) {
            return;
        }

        try {
            DB::beginTransaction();
            $width = $this->getWidth();
            $currentLft = $this->{static::leftColumn()};
            $currentRgt = $this->{static::rightColumn()};
            $query = static::withoutGlobalScope('ignore_root')->whereNot(static::primaryColumn(), $this->id);

            // T???m th???i ????? left v?? right c??c node con c???a node hi???n t???i ??? gi?? tr??? ??m
            $this->descendants()->update([
                static::leftColumn() => DB::raw(static::leftColumn() . " * (-1)"),
                static::rightColumn() => DB::raw(static::rightColumn() . " * (-1)"),
            ]);

            // Gi??? ?????nh node hi???n t???i b??? x??a kh???i c??y, c???p nh???t c??c node b??n ph???i c???a node hi???n t???i
            (clone $query)
                ->where(static::rightColumn(), '>', $this->{static::rightColumn()})
                ->update([static::rightColumn() => DB::raw(static::rightColumn() . " - $width")]);

            (clone $query)
                ->where(static::leftColumn(), '>', $this->{static::rightColumn()})
                ->update([static::leftColumn() => DB::raw(static::leftColumn() . " - $width")]);

            // T???o kho???ng tr???ng cho node hi???n t???i ??? node cha m???i, c???p nh???t c??c node b??n ph???i c???a node cha m???i
            $newParent = static::withoutGlobalScope('ignore_root')->find($newParentId);
            $newParentRgt = $newParent->{static::rightColumn()};

            (clone $query)
                ->where(static::rightColumn(), '>=', $newParentRgt)
                ->update([static::rightColumn() => DB::raw(static::rightColumn() . " + $width")]);

            (clone $query)
                ->where(static::leftColumn(), '>', $newParentRgt)
                ->update([static::leftColumn() => DB::raw(static::leftColumn() . " + $width")]);

            // C???p nh???t l???i node hi???n t???i theo node cha m???i
            $this->{static::leftColumn()} = $newParentRgt;
            $this->{static::rightColumn()} = $newParentRgt + $width - 1;
            $distance = $this->{static::rightColumn()} - $currentRgt;

            // C???p nh???t l???i c??c node con c?? left v?? right ??m
            static::query()
                ->where(static::leftColumn(), '<', 0 - $currentLft)
                ->where(static::rightColumn(), '>', 0 - $currentRgt)
                ->update([
                    static::leftColumn() => DB::raw("ABS(" . static::leftColumn() . ") + $distance"),
                    static::rightColumn() => DB::raw("ABS(" . static::rightColumn() . ") + $distance"),
                ]);

            DB::commit();
        } catch (Throwable $th) {
            DB::rollBack();
            throw $th;
        }
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function updateTreeOnDeleted(): void
    {
        try {
            DB::beginTransaction();
            // make sure that no unsaved changes affect the calculation
            $this->refresh();

            // move the child nodes to the parent node of the deleted node
            $this->descendants()->update([
                static::parentIdColumn() => $this->{static::parentIdColumn()},
                static::leftColumn() => DB::raw(static::leftColumn() . " - 1"),
                static::rightColumn() => DB::raw(static::rightColumn() . " - 1"),
            ]);

            // Update the nodes to the right of the deleted node
            static::withoutGlobalScope('ignore_root')
                ->where(static::rightColumn(), '>', $this->{static::rightColumn()})
                ->update([static::rightColumn() => DB::raw(static::rightColumn() . " - 2")]);

            static::withoutGlobalScope('ignore_root')
                ->where(static::leftColumn(), '>', $this->{static::rightColumn()})
                ->update([static::leftColumn() => DB::raw(static::leftColumn() . " - 2")]);

            DB::commit();
        } catch (Throwable $th) {
            DB::rollBack();
            throw $th;
        }
    }
}
