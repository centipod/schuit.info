<?php
declare(strict_types=1);
/** @var array<int, array<string, string>> $news_cards */

$news_cards = schuit_portal_home_publication_cards(2);
$metrics = schuit_portal_webtrees_metrics();
$feature_cards = [
    [
        'label' => 'Webtrees',
        'title' => 'Webtrees',
        'text' => 'Onderzoek personen, gezinnen en familietakken in onze genealogische database.',
        'url' => home_url('/tree/'),
        'cta' => 'Open Webtrees',
    ],
    [
        'label' => 'Publicaties',
        'title' => 'Publicaties',
        'text' => 'Raadpleeg onze publicaties over geslachten, familiegeschiedenis en genealogisch onderzoek.',
        'url' => schuit_portal_category_url('publicaties', 5),
        'cta' => 'Bekijk publicaties',
    ],
    [
        'label' => 'Stichting',
        'title' => 'De stichting',
        'text' => 'Lees over de stichting, het bestuur en de geslachten die wij onderzoeken.',
        'url' => schuit_portal_page_url('about', '/?page_id=2'),
        'cta' => 'Over de stichting',
    ],
];

get_header();
?>
<main class="site-main site-main--home archive-home">
  <section class="archive-hero">
    <div class="archive-hero__copy">
      <h1>Familiegeschiedenis zorgvuldig bewaard</h1>
      <p>Stichting Schu-y-i-ij-t verzamelt en bewaart gegevens, verhalen, beelden en publicaties over families met de naam Schuyt, Schuit, Schuijt en verwante namen.</p>
      <div class="archive-hero__actions">
        <a class="button button--primary" href="<?php echo esc_url(home_url('/tree/')); ?>">Open Webtrees</a>
        <a class="button button--ghost" href="<?php echo esc_url(schuit_portal_category_url('publicaties', 5)); ?>">Bekijk publicaties</a>
      </div>
    </div>
    <div class="archive-hero__image" aria-hidden="true">
      <img src="<?php echo esc_url(home_url('/banner.png')); ?>" alt="" loading="eager">
    </div>
  </section>

  <section class="archive-metrics" aria-label="Gegevens uit Webtrees">
    <div class="archive-metric">
      <span class="archive-metric__icon">P</span>
      <strong><?php echo esc_html(schuit_portal_metric_value($metrics['individuals'])); ?></strong>
      <span>Personen</span>
    </div>
    <div class="archive-metric">
      <span class="archive-metric__icon">G</span>
      <strong><?php echo esc_html(schuit_portal_metric_value($metrics['families'])); ?></strong>
      <span>Gezinnen</span>
    </div>
  </section>

  <section class="archive-feature-grid" aria-label="Belangrijke ingangen">
    <?php foreach ($feature_cards as $card) : ?>
      <article class="archive-feature-card">
        <span class="archive-feature-card__mark"><?php echo esc_html(substr($card['label'], 0, 1)); ?></span>
        <h2><?php echo esc_html($card['title']); ?></h2>
        <p><?php echo esc_html($card['text']); ?></p>
        <a href="<?php echo esc_url($card['url']); ?>"><?php echo esc_html($card['cta']); ?></a>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="archive-lower-grid">
    <div class="archive-news">
      <div class="archive-section-head">
        <h2>Recente publicaties</h2>
      </div>
      <div class="archive-news__list">
        <?php foreach ($news_cards as $card) : ?>
          <article class="archive-news-item">
            <div class="archive-news-item__thumb" aria-hidden="true"></div>
            <div>
              <h3><?php echo esc_html($card['title']); ?></h3>
              <time><?php echo esc_html($card['eyebrow']); ?></time>
              <p><?php echo esc_html($card['text']); ?></p>
              <a href="<?php echo esc_url($card['url']); ?>">Lees verder</a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
      <a class="button button--ghost archive-news__all" href="<?php echo esc_url(schuit_portal_category_url('publicaties', 5)); ?>">Alle publicaties</a>
    </div>

    <aside class="archive-about">
      <div class="archive-section-head">
        <h2>Over de stichting</h2>
      </div>
      <p>De stichting legt de geschiedenis vast van families met de naam Schuyt, Schuit, Schuijt en verwante familienamen. Zij beheert genealogische gegevens, publicaties, verhalen en historische beelden, en bevordert contact tussen onderzoekers en familieleden.</p>
      <a href="<?php echo esc_url(schuit_portal_page_url('about', '/?page_id=2')); ?>">Lees meer over de stichting</a>
    </aside>
  </section>
  <?php schuit_portal_sponsors(); ?>
</main>
<?php get_footer(); ?>
