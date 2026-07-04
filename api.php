<?php
/**
 * BBS API - InfinityFree
 * 使用 JSON 文件存储，无需数据库
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ---------- 数据文件路径 ----------
define('DATA_DIR', __DIR__ . '/data');
define('USERS_FILE', DATA_DIR . '/users.json');
define('POSTS_FILE', DATA_DIR . '/posts.json');
define('REPLIES_FILE', DATA_DIR . '/replies.json');
define('LIKES_FILE', DATA_DIR . '/likes.json');

// ---------- 初始化数据文件 ----------
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

function initFile($path, $default = []) {
    if (!file_exists($path)) {
        file_put_contents($path, json_encode($default, JSON_PRETTY_PRINT));
    }
}

initFile(USERS_FILE);
initFile(POSTS_FILE);
initFile(REPLIES_FILE);
initFile(LIKES_FILE);

// ---------- 辅助函数 ----------
function readJSON($file) {
    $data = file_get_contents($file);
    return json_decode($data, true) ?? [];
}

function writeJSON($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function error($msg, $code = 400) {
    respond(['success' => false, 'error' => $msg], $code);
}

function success($data = []) {
    respond(['success' => true] + $data);
}

// ---------- 初始化默认管理员 ----------
$users = readJSON(USERS_FILE);
$hasAdmin = false;
foreach ($users as $u) {
    if ($u['role'] === 'admin') { $hasAdmin = true; break; }
}
if (!$hasAdmin) {
    $users[] = [
        'id' => 1,
        'username' => 'admin',
        'password' => password_hash('admin123', PASSWORD_DEFAULT),
        'role' => 'admin',
        'created_at' => date('Y-m-d H:i:s')
    ];
    writeJSON(USERS_FILE, $users);
}

// ---------- 获取输入 ----------
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];

// ---------- 路由处理 ----------
switch ($action) {

    // ======================== 用户注册 ========================
    case 'register':
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        if (strlen($username) < 2 || strlen($username) > 20) {
            error('用户名长度需在2-20个字符之间');
        }
        if (strlen($password) < 4) {
            error('密码长度至少4位');
        }
        if (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u', $username)) {
            error('用户名只能包含字母、数字、下划线和中文');
        }
        $users = readJSON(USERS_FILE);
        foreach ($users as $u) {
            if ($u['username'] === $username) {
                error('用户名已存在');
            }
        }
        $newId = count($users) > 0 ? max(array_column($users, 'id')) + 1 : 1;
        $users[] = [
            'id' => $newId,
            'username' => $username,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'user',
            'created_at' => date('Y-m-d H:i:s')
        ];
        writeJSON(USERS_FILE, $users);
        success(['message' => '注册成功', 'username' => $username, 'role' => 'user']);
        break;

    // ======================== 用户登录 ========================
    case 'login':
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $users = readJSON(USERS_FILE);
        foreach ($users as $u) {
            if ($u['username'] === $username && password_verify($password, $u['password'])) {
                success([
                    'message' => '登录成功',
                    'username' => $u['username'],
                    'role' => $u['role'],
                    'user_id' => $u['id']
                ]);
            }
        }
        error('用户名或密码错误');
        break;

    // ======================== 获取帖子列表 ========================
    case 'get_posts':
        $posts = readJSON(POSTS_FILE);
        $likes = readJSON(LIKES_FILE);
        $replies = readJSON(REPLIES_FILE);
        $currentUser = $input['username'] ?? '';

        // 按置顶和日期排序
        usort($posts, function ($a, $b) {
            if ($a['is_pinned'] != $b['is_pinned']) {
                return $b['is_pinned'] - $a['is_pinned'];
            }
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        // 统计点赞数和回复数，并标记当前用户是否点赞
        $posts = array_map(function ($p) use ($likes, $replies, $currentUser) {
            $p['like_count'] = 0;
            $p['liked_by_me'] = false;
            foreach ($likes as $lk) {
                if ($lk['post_id'] == $p['id']) {
                    $p['like_count']++;
                    if ($lk['username'] === $currentUser) {
                        $p['liked_by_me'] = true;
                    }
                }
            }
            $p['reply_count'] = 0;
            foreach ($replies as $r) {
                if ($r['post_id'] == $p['id']) {
                    $p['reply_count']++;
                }
            }
            return $p;
        }, $posts);

        success(['posts' => $posts]);
        break;

    // ======================== 创建帖子 ========================
    case 'create_post':
        $username = trim($input['username'] ?? '');
        $title = trim($input['title'] ?? '');
        $content = trim($input['content'] ?? '');
        if (!$username) error('请先登录');
        if (!$title) error('请输入标题');
        if (!$content) error('请输入内容');
        if (mb_strlen($title) > 100) error('标题不能超过100个字符');
        if (mb_strlen($content) > 5000) error('内容不能超过5000个字符');

        $users = readJSON(USERS_FILE);
        $userExists = false;
        foreach ($users as $u) {
            if ($u['username'] === $username) { $userExists = true; break; }
        }
        if (!$userExists && $username !== 'guest') error('用户不存在');

        $posts = readJSON(POSTS_FILE);
        $newId = count($posts) > 0 ? max(array_column($posts, 'id')) + 1 : 1;
        $posts[] = [
            'id' => $newId,
            'username' => $username,
            'title' => $title,
            'content' => $content,
            'is_pinned' => 0,
            'created_at' => date('Y-m-d H:i:s')
        ];
        writeJSON(POSTS_FILE, $posts);
        success(['message' => '发帖成功', 'post_id' => $newId]);
        break;

    // ======================== 删除帖子 ========================
    case 'delete_post':
        $postId = intval($input['post_id'] ?? 0);
        $username = $input['username'] ?? '';
        if (!$postId) error('无效的帖子ID');
        if (!$username) error('请先登录');

        $users = readJSON(USERS_FILE);
        $user = null;
        foreach ($users as $u) {
            if ($u['username'] === $username) { $user = $u; break; }
        }
        if (!$user) error('用户不存在');

        $posts = readJSON(POSTS_FILE);
        $found = false;
        $newPosts = [];
        foreach ($posts as $p) {
            if ($p['id'] == $postId) {
                $found = true;
                if ($p['username'] !== $username && $user['role'] !== 'admin') {
                    error('无权删除此帖子');
                }
                // 允许删除，不添加到新数组
                continue;
            }
            $newPosts[] = $p;
        }
        if (!$found) error('帖子不存在');
        writeJSON(POSTS_FILE, $newPosts);

        // 同时删除该帖子下的所有回复和点赞
        $allReplies = readJSON(REPLIES_FILE);
        $allReplies = array_filter($allReplies, fn($r) => $r['post_id'] != $postId);
        writeJSON(REPLIES_FILE, array_values($allReplies));

        $allLikes = readJSON(LIKES_FILE);
        $allLikes = array_filter($allLikes, fn($l) => $l['post_id'] != $postId);
        writeJSON(LIKES_FILE, array_values($allLikes));

        success(['message' => '删除成功']);
        break;

    // ======================== 置顶/取消置顶 (管理员) ========================
    case 'toggle_pin':
        $postId = intval($input['post_id'] ?? 0);
        $username = $input['username'] ?? '';
        if (!$postId) error('无效的帖子ID');
        if (!$username) error('请先登录');

        $users = readJSON(USERS_FILE);
        $user = null;
        foreach ($users as $u) {
            if ($u['username'] === $username) { $user = $u; break; }
        }
        if (!$user || $user['role'] !== 'admin') error('仅管理员可置顶帖子');

        $posts = readJSON(POSTS_FILE);
        $updated = false;
        foreach ($posts as &$p) {
            if ($p['id'] == $postId) {
                $p['is_pinned'] = $p['is_pinned'] ? 0 : 1;
                $updated = true;
                $status = $p['is_pinned'] ? '已置顶' : '已取消置顶';
                break;
            }
        }
        if (!$updated) error('帖子不存在');
        writeJSON(POSTS_FILE, $posts);
        success(['message' => $status, 'is_pinned' => $p['is_pinned'] ?? 0]);
        break;

    // ======================== 获取回复 ========================
    case 'get_replies':
        $postId = intval($input['post_id'] ?? 0);
        if (!$postId) error('无效的帖子ID');
        $allReplies = readJSON(REPLIES_FILE);
        $postReplies = array_values(array_filter($allReplies, fn($r) => $r['post_id'] == $postId));
        // 按时间排序（旧的在前）
        usort($postReplies, fn($a, $b) => strtotime($a['created_at']) - strtotime($b['created_at']));
        success(['replies' => $postReplies]);
        break;

    // ======================== 创建回复 ========================
    case 'create_reply':
        $postId = intval($input['post_id'] ?? 0);
        $username = trim($input['username'] ?? '');
        $content = trim($input['content'] ?? '');
        if (!$postId) error('无效的帖子ID');
        if (!$username) error('请先登录');
        if (!$content) error('请输入回复内容');
        if (mb_strlen($content) > 2000) error('回复内容不能超过2000个字符');

        // 游客不能回复
        if ($username === 'guest') error('游客不能回复');

        // 检查帖子是否存在
        $posts = readJSON(POSTS_FILE);
        $postExists = false;
        foreach ($posts as $p) {
            if ($p['id'] == $postId) { $postExists = true; break; }
        }
        if (!$postExists) error('帖子不存在');

        $allReplies = readJSON(REPLIES_FILE);
        $newId = count($allReplies) > 0 ? max(array_column($allReplies, 'id')) + 1 : 1;
        $allReplies[] = [
            'id' => $newId,
            'post_id' => $postId,
            'username' => $username,
            'content' => $content,
            'created_at' => date('Y-m-d H:i:s')
        ];
        writeJSON(REPLIES_FILE, $allReplies);
        success(['message' => '回复成功', 'reply_id' => $newId]);
        break;

    // ======================== 删除回复 ========================
    case 'delete_reply':
        $replyId = intval($input['reply_id'] ?? 0);
        $username = $input['username'] ?? '';
        if (!$replyId) error('无效的回复ID');
        if (!$username) error('请先登录');

        $users = readJSON(USERS_FILE);
        $user = null;
        foreach ($users as $u) {
            if ($u['username'] === $username) { $user = $u; break; }
        }
        if (!$user) error('用户不存在');

        $allReplies = readJSON(REPLIES_FILE);
        $found = false;
        $newReplies = [];
        foreach ($allReplies as $r) {
            if ($r['id'] == $replyId) {
                $found = true;
                if ($r['username'] !== $username && $user['role'] !== 'admin') {
                    error('无权删除此回复');
                }
                continue;
            }
            $newReplies[] = $r;
        }
        if (!$found) error('回复不存在');
        writeJSON(REPLIES_FILE, array_values($newReplies));
        success(['message' => '删除回复成功']);
        break;

    // ======================== 点赞/取消点赞 ========================
    case 'toggle_like':
        $postId = intval($input['post_id'] ?? 0);
        $username = $input['username'] ?? '';
        if (!$postId) error('无效的帖子ID');
        if (!$username) error('请先登录');

        $allLikes = readJSON(LIKES_FILE);
        $index = -1;
        foreach ($allLikes as $i => $lk) {
            if ($lk['post_id'] == $postId && $lk['username'] === $username) {
                $index = $i;
                break;
            }
        }
        if ($index >= 0) {
            // 取消点赞
            array_splice($allLikes, $index, 1);
            writeJSON(LIKES_FILE, $allLikes);
            success(['message' => '已取消点赞', 'liked' => false]);
        } else {
            // 点赞
            $allLikes[] = [
                'id' => count($allLikes) > 0 ? max(array_column($allLikes, 'id')) + 1 : 1,
                'post_id' => $postId,
                'username' => $username,
                'created_at' => date('Y-m-d H:i:s')
            ];
            writeJSON(LIKES_FILE, $allLikes);
            success(['message' => '点赞成功', 'liked' => true]);
        }
        break;

    // ======================== 获取所有用户 (管理员) ========================
    case 'get_users':
        $username = $input['username'] ?? '';
        $users = readJSON(USERS_FILE);
        $user = null;
        foreach ($users as $u) {
            if ($u['username'] === $username) { $user = $u; break; }
        }
        if (!$user || $user['role'] !== 'admin') error('权限不足', 403);

        // 不返回密码
        $safeUsers = array_map(function ($u) {
            return [
                'id' => $u['id'],
                'username' => $u['username'],
                'role' => $u['role'],
                'created_at' => $u['created_at']
            ];
        }, $users);
        success(['users' => $safeUsers]);
        break;

    // ======================== 删除用户 (管理员) ========================
    case 'delete_user':
        $targetUsername = trim($input['target_username'] ?? '');
        $adminUsername = $input['username'] ?? '';
        if (!$targetUsername) error('请指定要删除的用户');
        if (!$adminUsername) error('请先登录');

        $users = readJSON(USERS_FILE);
        $admin = null;
        foreach ($users as $u) {
            if ($u['username'] === $adminUsername) { $admin = $u; break; }
        }
        if (!$admin || $admin['role'] !== 'admin') error('权限不足', 403);
        if ($targetUsername === 'admin') error('不能删除管理员账户');

        $newUsers = array_filter($users, fn($u) => $u['username'] !== $targetUsername);
        if (count($newUsers) === count($users)) error('用户不存在');
        writeJSON(USERS_FILE, array_values($newUsers));

        // 同时删除该用户的所有帖子、回复、点赞
        $posts = readJSON(POSTS_FILE);
        $posts = array_filter($posts, fn($p) => $p['username'] !== $targetUsername);
        writeJSON(POSTS_FILE, array_values($posts));

        $replies = readJSON(REPLIES_FILE);
        $replies = array_filter($replies, fn($r) => $r['username'] !== $targetUsername);
        writeJSON(REPLIES_FILE, array_values($replies));

        $likes = readJSON(LIKES_FILE);
        $likes = array_filter($likes, fn($l) => $l['username'] !== $targetUsername);
        writeJSON(LIKES_FILE, array_values($likes));

        success(['message' => "用户 {$targetUsername} 已删除"]);
        break;

    default:
        error('未知操作: ' . $action, 404);
}