<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;

Auth::requireMember();
Auth::refresh($pdo);
$isPro = Auth::isPro();

$modules = $pdo->query('SELECT * FROM content_modules WHERE is_published = 1 ORDER BY sort, id')->fetchAll();
$lessonStmt = $pdo->prepare(
    'SELECT * FROM content_lessons WHERE module_id = ? AND is_published = 1 ORDER BY sort, id'
);

$skool = setting('skool_url');

layout_header('Learn', 'member');
?>
<h1>🎓 SportCard101 School</h1>
<p class="sub">Work through the modules below.
    <?php if (!$isPro): ?>Free lessons are open; <a href="/member/account.php">Pro</a> unlocks everything.<?php endif; ?>
    <?php if ($skool): ?> · <a href="<?= e($skool) ?>" target="_blank" rel="noopener">Join the community on Skool →</a><?php endif; ?>
</p>

<?php if (!$modules): ?>
    <div class="empty">Lessons are being added — check back soon.</div>
<?php else: foreach ($modules as $m):
    $lessonStmt->execute([$m['id']]);
    $lessons = $lessonStmt->fetchAll();
?>
    <section class="panel" id="m<?= (int)$m['id'] ?>">
        <div class="panel-head"><h2><?= e($m['title']) ?></h2></div>
        <?php if ($m['summary']): ?><p class="sub"><?= e($m['summary']) ?></p><?php endif; ?>
        <?php if (!$lessons): ?>
            <p class="sub">No lessons yet.</p>
        <?php else: foreach ($lessons as $l):
            $locked = !$isPro && !$l['is_free'];
        ?>
            <details class="lesson<?= $locked ? ' locked' : '' ?>"<?= $locked ? '' : '' ?>>
                <summary>
                    <?= e($l['title']) ?>
                    <?php if ($l['is_free']): ?><span class="tag-free">FREE</span><?php elseif ($locked): ?><span class="tag-pro">🔒 PRO</span><?php endif; ?>
                </summary>
                <?php if ($locked): ?>
                    <p class="sub">This lesson is for Pro members. <a href="/member/account.php">Upgrade →</a></p>
                <?php else: ?>
                    <?php if ($l['video_url']): ?><p><a class="btn btn-sm" href="<?= e($l['video_url']) ?>" target="_blank" rel="noopener">▶ Watch video</a></p><?php endif; ?>
                    <div class="lesson-body"><?= nl2br(e($l['body'] ?? '')) ?></div>
                <?php endif; ?>
            </details>
        <?php endforeach; endif; ?>
    </section>
<?php endforeach; endif; ?>
<?php
layout_footer();
