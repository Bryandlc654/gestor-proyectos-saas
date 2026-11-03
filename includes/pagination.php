<?php
// includes/pagination.php - Controles de paginación reutilizables
// Requiere $pagination (resultado de paginate()).
if (empty($pagination) || $pagination['total_pages'] <= 1) {
    return;
}

$current = $pagination['page'];
$last = $pagination['total_pages'];
$from = $pagination['offset'] + 1;
$to = min($pagination['offset'] + $pagination['per_page'], $pagination['total']);

// Ventana de páginas alrededor de la actual
$window = array_unique(array_filter(
    [1, $current - 1, $current, $current + 1, $last],
    fn($p) => $p >= 1 && $p <= $last
));
sort($window);
?>
<nav class="flex flex-col sm:flex-row items-center justify-between gap-3 mt-6" aria-label="Paginación">
    <p class="text-xs text-slate-500">
        Mostrando <strong class="text-slate-800"><?= $from ?>–<?= $to ?></strong> de <strong class="text-slate-800"><?= $pagination['total'] ?></strong>
    </p>
    <div class="flex items-center gap-1">
        <?php if ($current > 1): ?>
            <a href="<?= e(pageUrl($current - 1)) ?>" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold text-slate-600 hover:bg-slate-100 transition" aria-label="Página anterior">&larr;</a>
        <?php endif; ?>

        <?php $prev = 0; foreach ($window as $p): ?>
            <?php if ($p - $prev > 1): ?>
                <span class="px-1.5 text-xs text-slate-400">…</span>
            <?php endif; ?>
            <?php if ($p === $current): ?>
                <span class="px-3 py-1.5 rounded-lg text-xs font-bold bg-brand-600 text-white" aria-current="page"><?= $p ?></span>
            <?php else: ?>
                <a href="<?= e(pageUrl($p)) ?>" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-600 hover:bg-slate-100 transition"><?= $p ?></a>
            <?php endif; ?>
        <?php $prev = $p; endforeach; ?>

        <?php if ($current < $last): ?>
            <a href="<?= e(pageUrl($current + 1)) ?>" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold text-slate-600 hover:bg-slate-100 transition" aria-label="Página siguiente">&rarr;</a>
        <?php endif; ?>
    </div>
</nav>
