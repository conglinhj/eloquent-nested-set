<?php

namespace Linhnc\EloquentNestedSet\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    public static function booted(): void
    {
        // Ignore root node in global scope
        static::addGlobalScope('ignore_root', function (Builder $builder) {
            $tableName = (new static)->getTable();
            $builder->where($tableName . '.id', '<>', static::rootId());
        });
        static::saving(function (Model $model) {
            if (empty($model->{static::parentIdColumn()})) {
                $model->{static::parentIdColumn()} = static::rootId();
            }
            DB::listen(function ($query) {
                Log::channel('eloquent_nested_set')->debug('___', (array)$query);
            });
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
     * Lấy tất cả các entity cha, sắp xếp theo thứ tự entity cha gần nhất đầu tiên.
     *
     * Các entity cha trong 1 cây sẽ có
     * - left nhỏ hơn left của entity hiện tại
     * - right lớn hơn right của entity hiện tại
     */
    public function getAncestors()
    {
        return $this->ancestors()->orderBy(static::leftColumn(), 'DESC')->get();
    }

    /**
     * Lấy tất cả các entity con
     *
     * Các entity con trong 1 cây sẽ có
     * - left lớn hơn left của entity hiện tại
     * - right nhỏ hơn right của entity hiện tại
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
     * Get all entities in nested array
     */
    public static function getTree(): Collection
    {
        $nodes = static::all()->groupBy(static::parentIdColumn());
        $tree = collect([]);
        $tree->push(...$nodes->get(static::rootId()) ?? []);

        $getChildrenFunc = null;
        $getChildrenFunc = function ($tree) use (&$getChildrenFunc, $nodes) {
            foreach ($tree as $item) {
                $item->children = $nodes->get($item->id) ?: [];
                $getChildrenFunc($item->children);
            }
        };

        $getChildrenFunc($tree);
        return $tree;
    }

    /**
     * Get all parent in nested array
     */
    public function getAncestorsTree()
    {
        // TODO
    }

    /**
     * Get all descendants in nested array
     */
    public function getDescendantsTree()
    {
        // TODO
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

            // Node mới sẽ được thêm vào sau (bên phải) các nodes cùng cha
            $this->{static::leftColumn()} = $parentRgt;
            $this->{static::rightColumn()} = $parentRgt + 1;
            $width = $this->getWidth();

            // Cập nhật các node bên phải của node cha
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
            $query = static::withoutGlobalScope('ignore_root')->whereNot('id', $this->id);

            // Tạm thời để left và right các node con của node hiện tại ở giá trị âm
            $this->descendants()->update([
                static::leftColumn() => DB::raw(static::leftColumn() . " * (-1)"),
                static::rightColumn() => DB::raw(static::rightColumn() . " * (-1)"),
            ]);

            // Giả định node hiện tại bị xóa khỏi cây, cập nhật các node bên phải của node hiện tại
            (clone $query)
                ->where(static::rightColumn(), '>', $this->{static::rightColumn()})
                ->update([static::rightColumn() => DB::raw(static::rightColumn() . " - $width")]);

            (clone $query)
                ->where(static::leftColumn(), '>', $this->{static::rightColumn()})
                ->update([static::leftColumn() => DB::raw(static::leftColumn() . " - $width")]);

            // Tạo khoảng trống cho node hiện tại ở node cha mới, cập nhật các node bên phải của node cha mới
            $newParent = static::withoutGlobalScope('ignore_root')->find($newParentId);
            $newParentRgt = $newParent->{static::rightColumn()};

            (clone $query)
                ->where(static::rightColumn(), '>=', $newParentRgt)
                ->update([static::rightColumn() => DB::raw(static::rightColumn() . " + $width")]);

            (clone $query)
                ->where(static::leftColumn(), '>', $newParentRgt)
                ->update([static::leftColumn() => DB::raw(static::leftColumn() . " + $width")]);

            // Cập nhật lại node hiện tại theo node cha mới
            $this->{static::leftColumn()} = $newParentRgt;
            $this->{static::rightColumn()} = $newParentRgt + $width - 1;
            $distance = $this->{static::rightColumn()} - $currentRgt;

            // Cập nhật lại các node con có left và right âm
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
