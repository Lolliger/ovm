<?php
$kicker = e(c('conference.edition')) . ' · ' . e(portal_label($account, true));
$heading = 'Photo gallery';
require __DIR__ . '/_pagehead.php';
$canUpload = $viewer['reg'] && gallery_uploads_open() && $album === 'community';
$official = gallery_official_released();
$photoUrl = fn (array $p, string $q = '') => portal_link('gallery') . '?photo=' . rawurlencode($p['id']) . $q;
?>
<section class="section">
  <div class="container gallery-page">
    <div class="portal-top">
      <p><a href="<?= e(portal_home()) ?>">← Back to the <?= e(portal_label($account)) ?></a></p>
      <?php if ($photos): ?><a class="btn-ghost" href="<?= e(portal_link('gallery') . '?zip=' . $album) ?>">Download all (ZIP)</a><?php endif; ?>
    </div>
    <?php if ($message): ?><div class="notice notice-ok" role="status"><?= e($message) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="notice notice-error" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <?php if ($official): ?>
      <nav class="gallery-tabs" aria-label="Albums">
        <a href="<?= e(gallery_link('official')) ?>"<?= $album === 'official' ? ' aria-current="page"' : '' ?>>Official photos <span><?= count(gallery_photos('official')) ?></span></a>
        <a href="<?= e(gallery_link()) ?>"<?= $album === 'community' ? ' aria-current="page"' : '' ?>>Everyone’s photos <span><?= count(gallery_photos('community')) ?></span></a>
      </nav>
    <?php endif; ?>

    <?php if ($album === 'community'): ?>
      <div class="prose"><?= md(c('portal.gallery_intro') ?: 'Share your photos of the conference with everyone – so that in the end we have a collection from every perspective. Everybody who is logged in can see them; they are not public.') ?></div>
      <?php if ($canUpload): ?>
        <form class="portal-card gallery-upload" method="post" enctype="multipart/form-data" action="<?= e(portal_link('gallery')) ?>" data-batch-upload>
          <?= csrf_field() ?>
          <input type="hidden" name="a" value="upload">
          <div class="field"><label for="g-photos">Add photos</label>
            <input id="g-photos" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp,image/*" multiple required></div>
          <div class="gallery-upload-row">
            <button class="btn" type="submit">Upload</button>
            <span class="muted small" data-progress>You can choose many photos at once. Only upload photos you took yourself, and respect people who do not want to be photographed.</span>
          </div>
        </form>
      <?php elseif ($viewer['reg'] && !gallery_uploads_open()): ?>
        <p class="muted">Uploading new photos is closed.</p>
      <?php endif; ?>
      <?php if (!$official): ?><p class="muted small">The official photos by our photographer will appear here after the conference.</p><?php endif; ?>
    <?php else: ?>
      <div class="prose"><p>Photos by our official photographer. You may download them for your own use.</p></div>
    <?php endif; ?>

    <?php if (!$photos): ?>
      <p class="muted"><?= $album === 'community' ? 'No photos yet – be the first to share one!' : 'No photos yet.' ?></p>
    <?php else: ?>
      <ul class="gallery-grid" data-lightbox>
        <?php foreach ($photos as $i => $p): $mine = $viewer['reg'] && ($p['by'] ?? '') === $viewer['reg']; ?>
          <li>
            <a href="<?= e($photoUrl($p)) ?>" data-full="<?= e($photoUrl($p)) ?>" data-download="<?= e($photoUrl($p, '&download=1')) ?>">
              <img src="<?= e($photoUrl($p, '&size=t')) ?>" alt="Photo <?= $i + 1 ?>" loading="lazy" width="<?= (int) ($p['w'] ?? 0) ?>" height="<?= (int) ($p['h'] ?? 0) ?>">
            </a>
            <?php if ($mine && $album === 'community'): ?>
              <form method="post" action="<?= e(portal_link('gallery')) ?>" data-confirm="Delete this photo?"><?= csrf_field() ?><input type="hidden" name="a" value="delete"><input type="hidden" name="id" value="<?= e($p['id']) ?>"><button class="gallery-del" type="submit" title="Delete your photo" aria-label="Delete your photo">✕</button></form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>
<dialog class="lightbox" id="lightbox" aria-label="Photo">
  <img alt="">
  <div class="lightbox-bar">
    <button type="button" data-lb="prev" aria-label="Previous photo">←</button>
    <span data-lb="count"></span>
    <button type="button" data-lb="next" aria-label="Next photo">→</button>
    <a data-lb="download" href="#">Download ↓</a>
    <button type="button" data-lb="close" aria-label="Close">✕</button>
  </div>
</dialog>
<script src="<?= e(url('assets/js/upload.js')) ?>?v=<?= filemtime(ROOT . '/assets/js/upload.js') ?>" defer></script>
