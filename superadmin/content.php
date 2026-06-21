<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;

Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    switch ($_POST['action'] ?? '') {
        case 'module_save':
            $id = (int)($_POST['id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $summary = trim((string)($_POST['summary'] ?? ''));
            $sort = (int)($_POST['sort'] ?? 0);
            $pub  = isset($_POST['is_published']) ? 1 : 0;
            if ($title !== '') {
                if ($id) {
                    $pdo->prepare('UPDATE content_modules SET title=?, summary=?, sort=?, is_published=? WHERE id=?')
                        ->execute([$title,$summary,$sort,$pub,$id]);
                } else {
                    $pdo->prepare('INSERT INTO content_modules (title, slug, summary, sort, is_published) VALUES (?,?,?,?,?)')
                        ->execute([$title, slugify($title), $summary, $sort, $pub]);
                }
                flash('success', 'Module saved.');
            }
            break;
        case 'module_delete':
            $pdo->prepare('DELETE FROM content_modules WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
            flash('info', 'Module deleted.');
            break;
        case 'lesson_save':
            $id = (int)($_POST['id'] ?? 0);
            $mid = (int)($_POST['module_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $body = (string)($_POST['body'] ?? '');
            $video = trim((string)($_POST['video_url'] ?? ''));
            $free = isset($_POST['is_free']) ? 1 : 0;
            $pub  = isset($_POST['is_published']) ? 1 : 0;
            $sort = (int)($_POST['sort'] ?? 0);
            if ($title !== '' && $mid) {
                if ($id) {
                    $pdo->prepare('UPDATE content_lessons SET title=?, body=?, video_url=?, is_free=?, is_published=?, sort=? WHERE id=?')
                        ->execute([$title,$body,$video ?: null,$free,$pub,$sort,$id]);
                } else {
                    $pdo->prepare('INSERT INTO content_lessons (module_id, title, body, video_url, is_free, is_published, sort) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$mid,$title,$body,$video ?: null,$free,$pub,$sort]);
                }
                flash('success', 'Lesson saved.');
            }
            break;
        case 'lesson_delete':
            $pdo->prepare('DELETE FROM content_lessons WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
            flash('info', 'Lesson deleted.');
            break;
    }
    redirect('/superadmin/content.php');
}

$modules = $pdo->query('SELECT * FROM content_modules ORDER BY sort, id')->fetchAll();
$lstmt = $pdo->prepare('SELECT * FROM content_lessons WHERE module_id=? ORDER BY sort, id');

// Optional prefill for editing a lesson.
$editLesson = null;
if (isset($_GET['lesson'])) {
    $s = $pdo->prepare('SELECT * FROM content_lessons WHERE id=?');
    $s->execute([(int)$_GET['lesson']]);
    $editLesson = $s->fetch() ?: null;
}

layout_header('Content', 'admin');
?>
<h1>School content</h1>
<p class="sub">Build modules, then add lessons. Mark a lesson <strong>Free</strong> to preview it to non-paying members.</p>

<div class="card" style="margin-bottom:24px">
    <h2 style="margin-top:0">New module</h2>
    <form method="post" class="stack"><?= csrf_field() ?>
        <input type="hidden" name="action" value="module_save">
        <div class="row">
            <div><label>Title</label><input name="title" required></div>
            <div><label>Sort</label><input name="sort" type="number" value="0"></div>
        </div>
        <label>Summary</label><input name="summary">
        <label class="checkbox"><input type="checkbox" name="is_published" checked> Published</label>
        <div style="margin-top:12px"><button class="btn btn-primary" type="submit">Add module</button></div>
    </form>
</div>

<?php foreach ($modules as $m): $lstmt->execute([$m['id']]); $lessons = $lstmt->fetchAll(); ?>
    <section class="panel">
        <div class="panel-head">
            <h2><?= e($m['title']) ?> <?= $m['is_published'] ? '🟢' : '⚪' ?></h2>
            <form method="post" class="inline" onsubmit="return confirm('Delete module and its lessons?')"><?= csrf_field() ?>
                <input type="hidden" name="action" value="module_delete"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">Delete module</button>
            </form>
        </div>

        <table>
            <thead><tr><th>Lesson</th><th>Access</th><th>Pub</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($lessons as $l): ?>
                <tr>
                    <td><?= e($l['title']) ?></td>
                    <td><?= $l['is_free'] ? '<span class="tag-free">FREE</span>' : '<span class="tag-pro">PRO</span>' ?></td>
                    <td><?= $l['is_published'] ? '🟢' : '⚪' ?></td>
                    <td>
                        <a class="btn btn-sm" href="/superadmin/content.php?lesson=<?= (int)$l['id'] ?>#lf<?= (int)$m['id'] ?>">Edit</a>
                        <form method="post" class="inline" onsubmit="return confirm('Delete lesson?')"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="lesson_delete"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                            <button class="btn btn-sm btn-danger" type="submit">×</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php $ed = ($editLesson && (int)$editLesson['module_id'] === (int)$m['id']) ? $editLesson : null; ?>
        <details class="lesson" id="lf<?= (int)$m['id'] ?>"<?= $ed ? ' open' : '' ?>>
            <summary><?= $ed ? 'Edit lesson' : '+ Add lesson' ?></summary>
            <form method="post" class="stack"><?= csrf_field() ?>
                <input type="hidden" name="action" value="lesson_save">
                <input type="hidden" name="module_id" value="<?= (int)$m['id'] ?>">
                <input type="hidden" name="id" value="<?= (int)($ed['id'] ?? 0) ?>">
                <div class="row">
                    <div><label>Title</label><input name="title" value="<?= e($ed['title'] ?? '') ?>" required></div>
                    <div><label>Sort</label><input name="sort" type="number" value="<?= (int)($ed['sort'] ?? 0) ?>"></div>
                </div>
                <label>Video URL <small>(optional)</small></label><input name="video_url" value="<?= e($ed['video_url'] ?? '') ?>">
                <label>Lesson body</label><textarea name="body" rows="6"><?= e($ed['body'] ?? '') ?></textarea>
                <label class="checkbox"><input type="checkbox" name="is_free" <?= ($ed['is_free'] ?? 0) ? 'checked' : '' ?>> Free preview</label>
                <label class="checkbox"><input type="checkbox" name="is_published" <?= ($ed['is_published'] ?? 1) ? 'checked' : '' ?>> Published</label>
                <div style="margin-top:12px"><button class="btn btn-primary" type="submit"><?= $ed ? 'Save lesson' : 'Add lesson' ?></button>
                    <?php if ($ed): ?><a class="btn" href="/superadmin/content.php">Cancel</a><?php endif; ?></div>
            </form>
        </details>
    </section>
<?php endforeach; ?>
<?php
layout_footer();
