<?php
declare(strict_types=1);
get_header();
?>
<main class="site-main schuit-shell">
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
  <?php else : ?>
    <article class="card">
      <h1>Geen inhoud gevonden</h1>
      <p>Maak een pagina of bericht aan om deze portal te vullen.</p>
    </article>
  <?php endif; ?>
</main>
<?php get_footer(); ?>
