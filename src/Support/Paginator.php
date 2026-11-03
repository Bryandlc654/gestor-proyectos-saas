<?php

namespace App\Support;

final class Paginator
{
    /**
     * Calcula la página efectiva (acotada al rango válido), el offset y el total de páginas.
     */
    public static function paginate(int $totalItems, int $perPage, int $requestedPage): array
    {
        $perPage = max(1, $perPage);
        $totalPages = max(1, (int)ceil($totalItems / $perPage));
        $page = min(max(1, $requestedPage), $totalPages);

        return [
            'page'        => $page,
            'per_page'    => $perPage,
            'offset'      => ($page - 1) * $perPage,
            'total'       => $totalItems,
            'total_pages' => $totalPages,
        ];
    }
}
