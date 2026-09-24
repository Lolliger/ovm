<?php
$start = conference_start_iso();
$committees = c('committees', []);
$news = array_slice(published_news(), 0, 3);
$featured = array_values(array_filter(c('downloads', []), fn ($d) => !empty($d['featured']) && !empty($d['file'])));
$heroImage = c('home.hero_image');
?>
<section class="hero<?= $heroImage ? ' has-photo' : '' ?>">
  <?php if ($heroImage): ?>
    <img class="hero-photo" src="<?= e(media($heroImage)) ?>" alt="<?= e(c('home.hero_image_caption')) ?>">
    <div class="hero-scrim"></div>
  <?php endif; ?>
  <div class="container hero-inner">
    <?php if (c('home.hero_kicker')): ?><p class="kicker"><?= e(c('home.hero_kicker')) ?></p><?php endif; ?>
    <h1 class="hero-title"><?= emph(c('home.hero_title')) ?></h1>
    <?php if (c('home.hero_text')): ?><p class="hero-text"><?= e(c('home.hero_text')) ?></p><?php endif; ?>

    <div class="hero-meta">
      <div>
        <span class="meta-label">Next conference</span>
        <strong><?= e(c('conference.edition')) ?></strong>
        <span><?= e(date_range(c('conference.date_start'), c('conference.date_end'))) ?></span>
      </div>
      <?php if (c('home.show_countdown') && $start): ?>
        <div class="countdown" data-countdown="<?= e($start) ?>" aria-label="Time until the conference">
          <div><span data-unit="d">–</span><small>days</small></div>
          <div><span data-unit="h">–</span><small>hours</small></div>
          <div><span data-unit="m">–</span><small>minutes</small></div>
          <div><span data-unit="s">–</span><small>seconds</small></div>
          <p class="countdown-done" hidden>The conference is under way.</p>
        </div>
      <?php endif; ?>
    </div>

    <div class="hero-actions">
      <a class="btn btn-light" href="<?= e(url('register')) ?>">Register now</a>
      <a class="btn btn-ghost" href="<?= e(url('conference')) ?>">Programme &amp; venue</a>
    </div>
    <?php if ($heroImage && c('home.hero_image_caption')): ?>
      <p class="hero-caption"><?= e(c('home.hero_image_caption')) ?></p>
    <?php endif; ?>
  </div>
</section>

<section class="section">
  <div class="container split">
    <div class="prose">
      <h2><?= e(c('home.about_heading')) ?></h2>
      <?= md(c('home.about_text')) ?>
    </div>
    <?php if ($stats = c('home.stats', [])): ?>
      <dl class="stats">
        <?php foreach ($stats as $s): ?>
          <div><dt><?= e($s['label'] ?? '') ?></dt><dd><?= e($s['value'] ?? '') ?></dd></div>
        <?php endforeach; ?>
      </dl>
    <?php endif; ?>
  </div>
</section>

<?php if (c('conference.motto')): ?>
<section class="section motto">
  <div class="container">
    <p class="kicker"><?= e(c('conference.edition')) ?> · Theme</p>
    <p class="motto-text">“<?= e(c('conference.motto')) ?>”</p>
  </div>
</section>
<?php endif; ?>

<?php if ($committees): ?>
<section class="section">
  <div class="container">
    <div class="section-head">
      <h2>Committees</h2>
      <a class="more" href="<?= e(url('committees')) ?>">All committees →</a>
    </div>
    <div class="committee-grid">
      <?php foreach ($committees as $cm): ?>
        <a class="committee-card" href="<?= e(url('committees/' . $cm['slug'])) ?>">
          <span class="committee-abbr"><?= e($cm['abbr'] ?? '') ?></span>
          <span class="committee-name"><?= e($cm['name']) ?></span>
          <?php if (!empty($cm['level'])): ?><span class="tag tag-<?= e(slugify($cm['level'])) ?>"><?= e($cm['level']) ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($steps = c('home.steps', [])): ?>
<section class="section">
  <div class="container">
    <h2><?= e(c('home.steps_heading')) ?></h2>
    <ol class="steps">
      <?php foreach ($steps as $s): ?>
        <li><h3><?= e($s['title'] ?? '') ?></h3><p><?= e($s['text'] ?? '') ?></p></li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>
<?php endif; ?>

<?php if ($featured || c('site.email')): ?>
<section class="section">
  <div class="container split">
    <?php if ($featured): ?>
      <div>
        <h2>Downloads</h2>
        <ul class="file-list">
          <?php foreach ($featured as $d): ?>
            <li><a href="<?= e(media($d['file'])) ?>" download>
              <span class="file-title"><?= e($d['title']) ?></span>
              <span class="file-meta"><?= e(file_meta($d['file'])) ?></span>
            </a></li>
          <?php endforeach; ?>
        </ul>
        <a class="more" href="<?= e(url('downloads')) ?>">All downloads →</a>
      </div>
    <?php endif; ?>
    <div class="prose">
      <h2>Questions?</h2>
      <p>If you have any questions about <?= e(c('site.name')) ?>, write to us at
        <a href="mailto:<?= e(c('site.email')) ?>"><?= e(c('site.email')) ?></a><?= c('faq', []) ? ' or have a look at the <a href="' . e(url('faq')) . '">FAQ</a>' : '' ?>.</p>
      <p><?= e(c('conference.edition')) ?> takes place at the <?= e(c('conference.venue')) ?>.
        <?php if (c('conference.map_url')): ?><a href="<?= e(c('conference.map_url')) ?>" target="_blank" rel="noopener">Show on map</a><?php endif; ?></p>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($news): ?>
<section class="section">
  <div class="container">
    <div class="section-head">
      <h2>News</h2>
      <a class="more" href="<?= e(url('news')) ?>">All news →</a>
    </div>
    <div class="card-grid">
      <?php foreach ($news as $n): ?>
        <a class="card" href="<?= e(url('news/' . $n['slug'])) ?>">
          <?php if (!empty($n['image'])): ?><img class="card-photo" src="<?= e(media($n['image'])) ?>" alt="" loading="lazy"><?php endif; ?>
          <time datetime="<?= e($n['date']) ?>"><?= e(format_date($n['date'])) ?></time>
          <h3><?= e($n['title']) ?></h3>
          <p><?= e($n['excerpt'] ?? '') ?></p>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (c('home.quote')): ?>
<section class="section">
  <div class="container">
    <figure class="quote">
      <blockquote><?= e(c('home.quote')) ?></blockquote>
      <?php if (c('home.quote_author')): ?><figcaption><?= e(c('home.quote_author')) ?></figcaption><?php endif; ?>
    </figure>
  </div>
</section>
<?php endif; ?>

<?php if (c('home.cta_heading')): ?>
<section class="cta">
  <div class="container cta-inner">
    <div>
      <h2><?= e(c('home.cta_heading')) ?></h2>
      <p><?= e(c('home.cta_text')) ?></p>
    </div>
    <a class="btn btn-light" href="<?= e(url('register')) ?>">Register</a>
  </div>
</section>
<?php endif; ?>
