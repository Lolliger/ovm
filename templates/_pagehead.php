<section class="page-head">
  <div class="container">
    <?php if (!empty($kicker)): ?><p class="kicker"><?= $kicker ?></p><?php endif; ?>
    <h1><?= e($heading ?? $title) ?></h1>
    <?php if (!empty($lead)): ?><p class="lead"><?= e($lead) ?></p><?php endif; ?>
  </div>
</section>
