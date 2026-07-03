<?php
declare(strict_types=1);
get_header();
?>
<main class="site-main schuit-shell">
  <section class="hero hero--home">
    <div class="hero__inner">
      <div class="hero__copy">
        <span class="hero__eyebrow">SCHU-Y-I-IJ-T</span>
        <h1>De Stichting verzamelt gegevens, feiten, verhalen en beelden uit het heden en verleden.</h1>
        <p>
          Daarmee wil de Stichting uiteindelijk de geschiedenis van alle geslachten met de naam Schuyt, Schuit of Schuijt en/of namen waarin dit woord voorkomt, vastleggen. Tevens zet zij zich in om het leggen van contacten en het onderhouden van netwerken tussen personen met deze namen te bevorderen.
        </p>
        <div class="hero__actions">
          <a class="button button--primary" href="<?php echo esc_url(home_url('/tree/')); ?>">Stamboom - webtrees</a>
          <a class="button button--ghost" href="<?php echo esc_url(home_url('/schuit/?cat=5')); ?>">Publicaties</a>
        </div>
      </div>
      <div class="hero__aside card card--contrast">
        <span class="card__eyebrow">Publicaties</span>
        <h2>Ons blad "In hetzelfde Schu-y-i-ij-tje"</h2>
        <p>Ons blad "In hetzelfde Schu-y-i-ij-tje" verschijnt december 2023 voor de laatste keer. Vanaf 2024 zal dit een nieuwsbrief gaan worden en gaat er ook meer aandacht besteed worden aan andere namen waarin het woord SCHUIT zit. Alle nummers van het blad zijn digitaal beschikbaar en opvraagbaar via jan@schuit.info.</p>
        <div class="card-actions">
          <a class="button button--primary" href="<?php echo esc_url(home_url('/schuit/?cat=5')); ?>">Meer</a>
          <a class="button button--ghost button--ghost-dark" href="<?php echo esc_url(home_url('/tree/')); ?>">Stamboom - webtrees</a>
        </div>
      </div>
    </div>
  </section>

  <section class="featured-tree card">
    <div class="featured-tree__inner">
      <div>
        <h2>Stamboom - webtrees</h2>
        <p>In dit bestand staan de gegevens van de naam SCHUIT in alle variaties. Bijna 88.000 personen. Wel met in achtneming van de privacyregels. Dat betekent 100 jaar voor de geboortes, 75 jaar voor de huwelijken en 50 jaar voor de overlijdens.</p>
      </div>
      <div class="card-actions">
        <a class="button button--primary" href="<?php echo esc_url(home_url('/tree/')); ?>">Open webtrees</a>
        <a class="button button--ghost button--ghost-dark" href="mailto:jan@schuit.info">jan@schuit.info</a>
      </div>
    </div>
  </section>

  <section class="portal-section">
    <div class="section-heading">
      <span class="section-heading__eyebrow">Publicaties</span>
      <h2>Publicaties</h2>
      <p>Vanaf 2024 zal dit een nieuwsbrief gaan worden en gaat er ook meer aandacht besteed worden aan andere namen waarin het woord SCHUIT zit.</p>
    </div>

    <div class="cards-grid portal-grid">
      <?php
      $cards = [
          ['title' => 'Nieuwsbrief', 'text' => 'Vanaf 2024 geeft de Stichting met regelmaat een digitale nieuwsbrief uit. Hierin zal worden geprobeerd naar evenredigheid aandacht te besteden aan alle namen.', 'url' => home_url('/schuit/?p=179901'), 'cta' => 'Nieuwsbrief'],
          ['title' => 'Contact', 'text' => 'Voor meer informatie over de Stichting, het maandblad, de website of het donateursschap, kunt u contact met ons opnemen via de mail jan@schuit.info.', 'url' => home_url('/?page_id=242'), 'cta' => 'Contact'],
          ['title' => 'Meer', 'text' => 'Alle nummers van het blad zijn digitaal beschikbaar en opvraagbaar via jan@schuit.info.', 'url' => home_url('/schuit/?cat=5'), 'cta' => 'Meer'],
          ['title' => 'Stamboom - webtrees', 'text' => 'In dit bestand staan de gegevens van de naam SCHUIT in alle variaties. Bijna 88.000 personen. Wel met in achtneming van de privacyregels.', 'url' => home_url('/tree/'), 'cta' => 'Stamboom - webtrees'],
      ];

      foreach ($cards as $card) : ?>
        <article class="card route-card">
          <h3><?php echo esc_html($card['title']); ?></h3>
          <p><?php echo esc_html($card['text']); ?></p>
          <div class="card-actions">
            <a class="button button--primary" href="<?php echo esc_url($card['url']); ?>"><?php echo esc_html($card['cta']); ?></a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="portal-section feature-band">
    <div class="section-heading">
      <span class="section-heading__eyebrow">Stamboom - webtrees</span>
      <h2>Stamboom - webtrees</h2>
      <p>In dit bestand staan de gegevens van de naam SCHUIT in alle variaties. Bijna 88.000 personen.</p>
    </div>

    <div class="branch-strip">
      <article class="branch-card card">
        <h3>Privacy</h3>
        <p>Wel met in achtneming van de privacyregels. Dat betekent 100 jaar voor de geboortes, 75 jaar voor de huwelijken en 50 jaar voor de overlijdens.</p>
      </article>
      <article class="branch-card card">
        <h3>Meer</h3>
        <p>Alle nummers van het blad zijn digitaal beschikbaar en opvraagbaar via jan@schuit.info.</p>
      </article>
      <article class="branch-card card">
        <h3>Contact</h3>
        <p>Voor hulp bij het zoeken kunt u contact opnemen met Jan Schuit via email jan@schuit.info.</p>
      </article>
    </div>

    <div class="card-actions feature-band__actions">
      <a class="button button--primary" href="<?php echo esc_url(home_url('/tree/')); ?>">Stamboom - webtrees</a>
      <a class="button button--ghost button--ghost-dark" href="<?php echo esc_url(home_url('/schuit/?cat=5')); ?>">Publicaties</a>
    </div>
  </section>
</main>
<?php get_footer(); ?>
