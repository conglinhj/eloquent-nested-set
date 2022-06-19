# Eloquent Nested Set Model

It just my 'homework'

Automatically update tree when create, update, delete.

Note: you need to initialize a root node with parent_id=0, lft=1, rgt=2 when migrating

### How to use

Add ```use EloquentNestedSet;``` into your model, example:

```injectablephp
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Linhnc\EloquentNestedSet\Traits\EloquentNestedSet;

class Category extends Model
{
    use EloquentNestedSet;

    const ROOT_ID = 1; // optional, default: 1
    const LEFT = 'lft'; // optional, default: 'lft'
    const RIGHT = 'rgt'; // optional, default: 'rgt'
    const PARENT_ID = 'parent_id'; // optional, default: 'parent_id'

```

Functions:

- `tree`: create a query builder to interact with the tree
- `buildNestedTree`: create a nested array based on `parent_id`
- `getTree`: get all returned nodes in a nested array
- `getFlatTree`: get all returned nodes in a flat array sorted in order of child nodes after the parent node
- `getAncestors`: get all ancestor nodes of current node
- `getAncestorsTree`: get all ancestor nodes of the current node returned as nested array
- `getDescendants`: get all descendant nodes of the current node
- `getDescendantsTree`: get all descendant nodes of current node and return as nested array
- `paginateTree`: TODO