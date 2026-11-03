<?php
// includes/alert.php
$flash = getFlash();
if ($flash):
    $bgColor = 'bg-slate-900 text-white';
    $iconName = 'info';

    switch ($flash['type']) {
        case 'success':
            $bgColor = 'bg-emerald-600 text-white shadow-emerald-500/20';
            $iconName = 'check-circle-2';
            break;
        case 'error':
            $bgColor = 'bg-rose-600 text-white shadow-rose-500/20';
            $iconName = 'alert-octagon';
            break;
        case 'warning':
            $bgColor = 'bg-amber-500 text-white shadow-amber-500/20';
            $iconName = 'alert-triangle';
            break;
    }
?>
<div id="flash-toast" class="fixed top-5 right-5 z-50 flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg <?= $bgColor ?> transition-all duration-300 transform translate-y-0 opacity-100">
    <i data-lucide="<?= $iconName ?>" class="w-5 h-5 flex-shrink-0"></i>
    <span class="text-xs md:text-sm font-medium"><?= e($flash['message']) ?></span>
    <button onclick="document.getElementById('flash-toast').remove()" class="ml-2 text-white/80 hover:text-white">
        <i data-lucide="x" class="w-4 h-4"></i>
    </button>
</div>
<script>
    setTimeout(() => {
        const toast = document.getElementById('flash-toast');
        if (toast) {
            toast.classList.add('opacity-0', '-translate-y-2');
            setTimeout(() => toast.remove(), 300);
        }
    }, 4500);
</script>
<?php endif; ?>
