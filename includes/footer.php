<?php
/**
 * Page footer. Set $useCharts = true before including it on pages that draw
 * charts, so Chart.js is loaded only where it is needed.
 */
$layout = $layout ?? 'app';
?>
<?php if ($layout === 'plain'): ?>
</main>
<?php else: ?>
    </main>
</div>
<footer class="site-footer">
    <?= e(APP_NAME) ?> &middot; <?= e(setting('company_name', '')) ?> &middot; <?= date('Y') ?>
</footer>
<?php endif; ?>

<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php if (!empty($useCharts)): ?>
<script src="<?= e(asset('vendor/chart.umd.min.js')) ?>"></script>
<script src="<?= e(asset('js/charts.js')) ?>"></script>
<?php endif; ?>
</body>
</html>
