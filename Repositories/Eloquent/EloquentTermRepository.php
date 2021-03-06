<?php

namespace Modules\Taxonomy\Repositories\Eloquent;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\Taxonomy\Entities\Term;
use Modules\Taxonomy\Events\TermWasCreated;
use Modules\Taxonomy\Events\TermWasDeleted;
use Modules\Taxonomy\Events\TermWasUpdated;
use Modules\Taxonomy\Repositories\TermRepository;
use Modules\Core\Repositories\Eloquent\EloquentBaseRepository;

class EloquentTermRepository extends EloquentBaseRepository implements TermRepository
{
  /** @var  Term $model */
    protected $model;
    /**
     * Create a term
     *
     * @param $data
     * @return $this|Term
     */
    public function create($data)
    {
        /** @var Term $term */
        $term = $this->model->create($data);

        event(new TermWasCreated($term, $data));
        return $term;
    }

    /**
     * Update a term
     *
     * @param Term $term
     * @param array $data
     * @return mixed
     */
    public function update($term, $data)
    {
        $term->update($data);

        event(new TermWasUpdated($term, $data));
        return $term;
    }

    /**
     * Delete a term
     *
     * @param Term $term
     * @return mixed
     * @throws \Exception
     */
    public function destroy($term)
    {
        event(new TermWasDeleted($term));
        return $term->delete();
    }

  /**
   * @param int $id
   *
   * @return Term|null
   */
    public function find($id)
    {
        if (method_exists($this->model, 'translations')) {
            return $this->model->with('translations')->find($id);
        }

        return $this->model->find($id);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getQuery()
    {
        $query = $this->model->query();
        if (method_exists($this->model, 'translations')) {
            $query = $query->with('translations');
        }
        return $query;
    }

    /**
     * @param int $vid
     * @param \Illuminate\Database\Eloquent\Collection|Term[] $items
     * @param $parent
     * @param null $maxDepth
     * @return array
     */
    public function createTree($vid, $items, $parent, $maxDepth = null)
    {
        $tree = array();
        if (isset($items) && $items->isNotEmpty()) {
            $children = [];
            $parents = [];
            $terms = [];

            $children[$vid] = [];
            $parents[$vid] = [];
            foreach ($items as $term) {
                if ($term->parents()->count()) {
                    foreach ($term->parents as $pTerm) {
                        $children[$vid][$pTerm->id][] = $term->id;
                        $parents[$vid][$term->id][] = $pTerm->id;
                    }
                } else {
                    $children[$vid][0][] = $term->id;
                    $parents[$vid][$term->id][] = 0;
                }

                $terms[$vid][$term->id] = $term;
            }
          //dd($terms);
            $max_depth = (!isset($max_depth)) ? count($children[$vid]) : $max_depth;

          // Keeps track of the parents we have to process, the last entry is used
          // for the next processing step.
            $process_parents = array();
            $process_parents[] = $parent;

          // Loops over the parent terms and adds its children to the tree array.
          // Uses a loop instead of a recursion, because it's more efficient.
            while (count($process_parents)) {
                $parent = array_pop($process_parents);
              // The number of parents determines the current depth.
                $depth = count($process_parents);
                if ($max_depth > $depth && !empty($children[$vid][$parent])) {
                    $has_children = false;
                    $child = current($children[$vid][$parent]);
                    do {
                        if (empty($child)) {
                            break;
                        }
                        $term = $terms[$vid][$child];
                        if (isset($parents[$vid][$term->id])) {
                          // Clone the term so that the depth attribute remains correct
                          // in the event of multiple parents.
                            $term = clone $term;
                        }
                        $term->depth = $depth;
                        unset($term->parent);
                      //$term->parentIds = $parents[$vid][$term->id];
                        $tree[] = $term;
                        if (!empty($children[$vid][$term->id])) {
                            $has_children = true;

                          // We have to continue with this parent later.
                            $process_parents[] = $parent;
                          // Use the current term as parent for the next iteration.
                            $process_parents[] = $term->id;

                          // Reset pointers for child lists because we step in there more often
                          // with multi parents.
                            reset($children[$vid][$term->id]);
                          // Move pointer so that we get the correct term the next time.
                            next($children[$vid][$parent]);
                            break;
                        }
                    } while ($child = next($children[$vid][$parent]));

                    if (!$has_children) {
                      // We processed all terms in this hierarchy-level, reset pointer
                      // so that this function works the next time it gets called.
                        reset($children[$vid][$parent]);
                    }
                }
            }
        }

        return $tree;
    }

    /**
     * Return full tree of terms
     *
     * @param int $vid Vocabulary ID
     * @param int $parent Identity of parent term
     * @param null $maxDepth
     * @param null $status
     * @return array
     */
    public function getTree($vid, $parent = 0, $maxDepth = null, $status = null)
    {
        $items = $this->getQuery()->select([
        'taxonomy__terms.*',
        'taxonomy__terms_hierarchy.parent_id as parent',
        ])->leftJoin('taxonomy__terms_hierarchy', 'id', '=', 'term_id')
            ->with(['parents', 'children'])
        ->where('vocabulary_id', '=', $vid);
        if ($status !== null) {
            $items->where('status', '=', 1);
        }
        $items = $items->orderBy('pos', 'asc')->get();
        return $this->createTree($vid, $items, $parent, $maxDepth);
    }

	/**
	 * Paginating, ordering and searching through pages for server side index table
	 * @param Request $request
	 * @return LengthAwarePaginator
	 */
	public function serverPaginationFilteringFor(Request $request, $relations = []): LengthAwarePaginator
	{
		$query = $this->allWithBuilder($relations);

		if ($request->get('search') !== null) {
			$term = $request->get('search');
			$query->whereHas('translations', function ($q) use ($term) {
				$q->where('name', 'LIKE', "%$term%");
			})->orWhere('id', $term);
		}

		if ($request->get('order_by') !== null && $request->get('order') !== 'null') {
			$order = $request->get('order') === 'ascending' ? 'asc' : 'desc';

			$query->orderBy($request->get('order_by'), $order);
		} else {
			$query->orderBy('created_at', 'desc');
		}

		if ($request->get('group_by') !== null) {
			$query->groupBy(explode(",", $request->get('group_by')));
		}

		return $query->paginate($request->get('per_page', 10));
	}

	public function serverFilteringFor(Request $request, $relations = [])
	{
		$query = $this->allWithBuilder($relations);

		if ($request->get('search') !== null) {
			$term = $request->get('search');
			$query->where('id', $term);
		}

		if($request->get('vocabulary_id') !==null) {
			$query->where('vocabulary_id', '=', $request->get('vocabulary_id'));
		}

		if($request->get('entity_id') !==null && $request->get('entity') !== null) {
			$termables = \DB::table('taxonomy__termables')
			               ->where('termable_id', $request->get('entity_id'))
			               ->whereTermableType($request->get('entity'))
			               ->get();
			if ($termables) {
				$query->whereIn('id', $termables->pluck('term_id')->toArray());
			}
		}


		if ($request->get('order_by') !== null && $request->get('order') !== 'null') {
			$order = $request->get('order') === 'ascending' ? 'asc' : 'desc';

			$query->orderBy($request->get('order_by'), $order);
		} else {
			$query->orderBy('created_at', 'desc');
		}

		if ($request->get('group_by') !== null) {
			$query->groupBy(explode(",", $request->get('group_by')));
		}

		return $query->get();
	}

	public function allWithBuilder($relations = []): Builder
	{
		if (method_exists($this->model, 'translations')) {
			$relations = array_merge($relations, ['translations']);
		}
		if (!empty($relations)) {
			$with = [];
			foreach ($relations as $key => $relation) {
				if (is_callable($relation)) {
					if (method_exists($this->model, $key)) {
						$with[$key] = $relation;
					}
				} elseif (method_exists($this->model, $relation)) {
					array_push($with, $relation);
				}
			}

			if (!empty($with)) {
				return $this->model->with($with);
			}
		}

		return $this->model->newQuery();
	}

	/**
	 * @param Term $term
	 * @return mixed
	 */
	public function markAsOnline(Term $term)
	{
		return $this->update($term, ['status' => 1]);
	}

	/**
	 * @param Term $term
	 * @return mixed
	 */
	public function markAsOffline(Term $term)
	{
		return $this->update($term, ['status' => 0]);
	}

	/**
	 * @param array $termIds [int]
	 * @return mixed
	 */
	public function markMultipleAsOnline(array $termIds)
	{
		$terms = $this->allWithBuilder()->whereIn('id', $termIds)->get();
		foreach ($terms as $term) {
			$this->markAsOnline($term);
		}
	}

	/**
	 * @param array $termIds [int]
	 * @return mixed
	 */
	public function markMultipleAsOffline(array $termIds)
	{
		$terms = $this->allWithBuilder()->whereIn('id', $termIds)->get();
		foreach ($terms as $term) {
			$this->markAsOffline($term);
		}
	}
}
