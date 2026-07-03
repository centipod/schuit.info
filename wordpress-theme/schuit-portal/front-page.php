<?php
declare(strict_types=1);
/** @var array<int, array<string, string>> $news_cards */
/** @var array<int, array<string, string>> $story_cards */

$news_cards = schuit_portal_home_news_cards(3);
$story_cards = schuit_portal_home_story_cards();

get_header();
?>
<main class="site-main site-main--home schuit-shell">
  <section class="home-hero">
    <div class="home-hero__copy">
      <p class="home-hero__eyebrow">Stichting Schu-y-i-ij-t</p>
      <h1>De Stichting verzamelt gegevens, feiten, verhalen en beelden.</h1>
      <p class="home-hero__lede">
        Daarmee wil de Stichting uiteindelijk de geschiedenis van alle geslachten met de naam Schuyt, Schuit of Schuijt en/of namen waarin dit woord voorkomt, vastleggen. Tevens zet zij zich in voor het leggen van contacten en het onderhouden van netwerken tussen personen met deze namen.
      </p>
      <div class="home-hero__actions">
        <a class="button button--primary" href="<?php echo esc_url(home_url('/tree/')); ?>">Open de stamboom</a>
        <a class="button button--ghost button--ghost-dark" href="<?php echo esc_url(home_url('/schuit/?cat=5')); ?>">Bekijk publicaties</a>
      </div>
      <ul class="home-hero__stats" aria-label="Snelkoppelingen">
        <li>
          <strong>Genealogie</strong>
          <span>webtrees</span>
        </li>
        <li>
          <strong>Publicaties</strong>
          <span>Nieuwsbrief & archief</span>
        </li>
        <li>
          <strong>Contact</strong>
          <span>jan@schuit.info</span>
        </li>
      </ul>
    </div>

    <div class="home-hero__visual card">
      <img class="home-hero__image" src="<?php echo esc_url(home_url('/banner.png')); ?>" alt="" loading="eager">
      <div class="home-hero__badge">
        <span class="home-hero__badge-label">Familiearchief</span>
        <strong>Stamboom, publicaties en bronnen op één plek.</strong>
        <p>Een rustige, toegankelijke ingang voor familieonderzoek en archiefmateriaal.</p>
      </div>
    </div>
  </section>

  <section class="home-section">
    <div class="section-head section-head--split">
      <div>
        <p class="section-head__eyebrow">Recent nieuws</p>
        <h2>Nieuws</h2>
      </div>
      <a class="section-head__link" href="<?php echo esc_url(home_url('/?post_type=post')); ?>">Bekijk alle nieuws</a>
    </div>

    <div class="news-grid">
      <?php foreach ($news_cards as $card) : ?>
        <article class="news-card card">
          <a class="news-card__link" href="<?php echo esc_url($card['url']); ?>">
            <div class="news-card__media" style="background-image: linear-gradient(180deg, rgba(19, 38, 63, 0.05), rgba(19, 38, 63, 0.18)), url('<?php echo esc_url($card['image']); ?>');"></div>
            <div class="news-card__body">
              <p class="news-card__eyebrow"><?php echo esc_html($card['eyebrow']); ?></p>
              <h3><?php echo esc_html($card['title']); ?></h3>
              <p><?php echo esc_html($card['text']); ?></p>
              <span class="news-card__cta">Lees meer</span>
            </div>
          </a>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="family-band card">
    <div class="family-band__art" aria-hidden="true">
      <img src="<?php echo esc_url(home_url('/logo.png')); ?>" alt="" loading="lazy">
    </div>
    <div class="family-band__content">
      <p class="section-head__eyebrow">Stamboom - webtrees</p>
      <h2>Stamboom - webtrees</h2>
      <p>In dit bestand staan de gegevens van de naam SCHUIT in alle variaties. Bijna 88.000 personen. Wel met in achtneming van de privacyregels. Dat betekent 100 jaar voor de geboortes, 75 jaar voor de huwelijken en 50 jaar voor de overlijdens.</p>
      <div class="home-hero__actions">
        <a class="button button--primary" href="<?php echo esc_url(home_url('/tree/')); ?>">Open webtrees</a>
        <a class="button button--ghost button--ghost-dark" href="mailto:jan@schuit.info">Neem contact op</a>
      </div>
    </div>
    <div class="family-band__list-wrap">
      <ul class="family-band__list">
        <li>
          <strong>Privacy</strong>
          <span>Levensdata zijn afgeschermd volgens de geldende regels.</span>
        </li>
        <li>
          <strong>Nauwkeurig</strong>
          <span>Onderzoek en bronnen worden zorgvuldig bijgehouden.</span>
        </li>
        <li>
          <strong>Levend archief</strong>
          <span>Familiegeschiedenis blijft zichtbaar voor volgende generaties.</span>
        </li>
      </ul>
    </div>
  </section>

  <section class="home-section">
    <div class="section-head section-head--split">
      <div>
        <p class="section-head__eyebrow">Uitgelichte verhalen</p>
        <h2>Verhalen en archief</h2>
      </div>
      <a class="section-head__link" href="<?php echo esc_url(home_url('/verhalen/')); ?>">Bekijk alles</a>
    </div>

    <div class="story-grid">
      <?php foreach ($story_cards as $card) : ?>
        <article class="story-card card">
          <div class="story-card__media" style="background-image: linear-gradient(180deg, rgba(247, 243, 236, 0.08), rgba(19, 38, 63, 0.14)), url('<?php echo esc_url($card['image']); ?>');"></div>
          <div class="story-card__body">
            <p class="story-card__eyebrow"><?php echo esc_html($card['eyebrow']); ?></p>
            <h3><?php echo esc_html($card['title']); ?></h3>
            <p><?php echo esc_html($card['text']); ?></p>
            <a class="story-card__cta" href="<?php echo esc_url($card['url']); ?>">Lees verder</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
</main>
<?php get_footer(); ?>
