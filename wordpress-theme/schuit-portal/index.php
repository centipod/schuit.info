<?php
declare(strict_types=1);
get_header();
?>
<main class="site-main schuit-shell">
  <?php if (is_category('publicaties')) : ?>
    <header class="entry-header">
      <h1>Publicaties</h1>
      <p>Lees onze publicaties over familiegeschiedenis en genealogisch onderzoek.</p>
      <p>Vanaf 2024 verschijnt er een digitale nieuwsbrief. Aanmelden kan via <a href="mailto:jan@schuit.info">jan@schuit.info</a>. Ook de digitale nummers van het voormalige <a href="<?php echo esc_url(schuit_portal_page_url('kwartaalblad-in-hetzelfde-schu-y-i-ij-tje', '/?page_id=274')); ?>">kwartaalblad</a> zijn daar op te vragen.</p>
    </header>
  <?php elseif (is_archive()) : ?>
    <h1><?php echo esc_html(get_the_archive_title()); ?></h1>
  <?php elseif (is_search()) : ?>
    <h1>Zoekresultaten voor <?php echo esc_html(get_search_query()); ?></h1>
  <?php endif; ?>
  <?php if (have_posts()) : ?>
    <section class="cards-grid">
      <?php while (have_posts()) : the_post(); ?>
        <article class="card">
          <h2 class="entry-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
          <p class="meta"><?php echo esc_html(get_the_date()); ?></p>
          <?php the_excerpt(); ?>
        </article>
      <?php endwhile; ?>
    </section>
    <?php the_posts_pagination(['prev_text' => 'Vorige', 'next_text' => 'Volgende', 'screen_reader_text' => 'Meer publicaties']); ?>
  <?php else : ?>
    <article class="card">
      <h1>Geen inhoud gevonden</h1>
      <p>Er zijn hier geen berichten gevonden. Ga naar <a href="<?php echo esc_url(home_url('/')); ?>">Home</a> of bekijk de publicaties.</p>
    </article>
  <?php endif; ?>
</main>
<?php get_footer(); ?>
