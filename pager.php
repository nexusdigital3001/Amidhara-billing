<?php
/* Expects: $page (current), $pages (total), $baseQs (querystring without p=),
   and optionally $totalRows. Included by list pages. */
if (($pages ?? 1) > 1):
    $mk = function (int $pnum) use ($baseQs) {
        $q = $baseQs ? $baseQs . '&' : '';
        return '?' . $q . 'p=' . $pnum;
    };
?>
<div class="pager">
  <a class="btn sm<?= $page <= 1 ? ' disabled' : '' ?>"
     <?= $page > 1 ? 'href="' . h($mk($page - 1)) . '"' : '' ?>>‹ Prev</a>

  <?php
  // show a compact window of page numbers
  $start = max(1, $page - 2);
  $end   = min($pages, $page + 2);
  if ($start > 1) echo '<a class="btn sm" href="' . h($mk(1)) . '">1</a>' . ($start > 2 ? '<span class="pdots">…</span>' : '');
  for ($i = $start; $i <= $end; $i++):
  ?>
    <a class="btn sm<?= $i === $page ? ' on' : '' ?>" href="<?= h($mk($i)) ?>"><?= $i ?></a>
  <?php endfor;
  if ($end < $pages) echo ($end < $pages - 1 ? '<span class="pdots">…</span>' : '') . '<a class="btn sm" href="' . h($mk($pages)) . '">' . $pages . '</a>';
  ?>

  <a class="btn sm<?= $page >= $pages ? ' disabled' : '' ?>"
     <?= $page < $pages ? 'href="' . h($mk($page + 1)) . '"' : '' ?>>Next ›</a>

  <span class="pinfo">Page <?= $page ?> of <?= $pages ?></span>
</div>
<?php endif; ?>
