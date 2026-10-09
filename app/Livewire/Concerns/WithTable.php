<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Búsqueda, orden y paginación para listados.
 *   use WithTable;  protected array $sortable = ['name', 'created_at'];
 *   $query->tap(fn ($q) => $this->applySort($q))->paginate($this->perPage)
 */
trait WithTable
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'orden', except: '')]
    public string $sortBy = '';

    #[Url(as: 'dir', except: 'desc')]
    public string $sortDir = 'desc';

    public int $perPage = 25;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    /** Resetea página al cambiar cualquier filtro (propiedades que empiezan con "filter"). */
    public function updated($property): void
    {
        if (str_starts_with($property, 'filter')) {
            $this->resetPage();
        }
    }

    public function sort(string $column): void
    {
        if (property_exists($this, 'sortable') && ! in_array($column, $this->sortable, true)) {
            return;
        }
        $this->sortDir = $this->sortBy === $column && $this->sortDir === 'asc' ? 'desc' : 'asc';
        $this->sortBy = $column;
    }

    protected function applySort($query, string $default = 'id', string $defaultDir = 'desc')
    {
        $column = $this->sortBy ?: $default;
        if (property_exists($this, 'sortable') && $this->sortBy && ! in_array($this->sortBy, $this->sortable, true)) {
            $column = $default;
        }

        return $query->orderBy($column, $this->sortBy ? ($this->sortDir === 'asc' ? 'asc' : 'desc') : $defaultDir);
    }
}
