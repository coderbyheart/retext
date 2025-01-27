<?php

require_once __DIR__ . '/../config/config.php';

if (DEV) {
    error_reporting(-1);
    ini_set('display_errors', 1);
}
setlocale(LC_ALL, 'de_DE.utf8');

require_once __DIR__ . '/../vendor/silex.phar';
require_once __DIR__ . '/../vendor/php-markdown/markdown.php';

function listFiles()
{
    if (!apc_exists('files-ttl')) {
        apc_store('files-ttl', '-', 60 * 60);
        $files = array();
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(DROPBOX)) as $file) {
            if (is_dir($file->getPathname())) continue;
            if (substr($file->getPathname(), -1) == '~') continue;
            $type = substr($file->getPathname(), -3);
            $mtime = filemtime($file->getPathname());
            $files[] = array('type' => $type, 'file' => rawurlencode(str_replace(DROPBOX, '', $file->getPathname())), 'label' => substr($file->getFilename(), 0, -4), 'modified' => round((time() - $mtime) / 86400));
            $sort[] = $mtime;
        }
        array_multisort($sort, SORT_DESC, $files);
        apc_store('files', $files);
    }
    return apc_fetch('files');
}

function listDocuments()
{
    $files = listFiles();
    return array_filter($files, function($file)
    {
        return strstr(urldecode($file['file']), '/') === false;
    });
}

function filterList(array $list)
{
    return array_map(function($item)
    {
        return $item->screen_name;
    }, $list);
}

function getFriends()
{
    if (!apc_exists('friends-ttl')) {
        apc_store('friends-ttl', '-', 60 * 60 * 24);
        $friendsList = json_decode(file_get_contents('https://api.twitter.com/1/lists/members.json?slug=friends&owner_screen_name=retext&skip_status=1'));
        $friends = filterList($friendsList->users);
        apc_store('friends', $friends);
    }
    return apc_fetch('friends');
}

function getTeam()
{
    if (!apc_exists('team-ttl')) {
        apc_store('team-ttl', '-', 60 * 60 * 24);
        $teamList = json_decode(file_get_contents('https://api.twitter.com/1/lists/members.json?slug=team&owner_screen_name=retext&include_entities=0&skip_status=1'));
        $teamList = filterList($teamList->users);
        $teamList[] = 'retext';
        apc_store('team', $teamList);
    }
    return apc_fetch('team');
}

function dotFilter($content)
{
    // Replace dot graphs
    $dotstart = '[dot]';
    $dotend = '[/dot]';
    $nchart = 0;
    while ($pos = stripos($content, $dotstart)) {
        $posend = stripos($content, $dotend);
        $dotdata = substr($content, $pos + strlen($dotstart), $posend - $pos - strlen($dotend));
        $content = substr($content, 0, $pos) . sprintf('![Diagramm %d](/dot?data=%s)', ++$nchart, rawurlencode($dotdata)) . substr($content, $posend + strlen($dotend));
    }
    return $content;
}

function mediaFilter($content)
{
    preg_match_all('/!\[[^\]+]+\]\(([^\)]+)\)/', $content, $matches, PREG_SET_ORDER);
    foreach ($matches as $k => $match) {
        $newsrc = '/file?file=' . rawurlencode($match[1]);
        if (substr($newsrc, -3) != 'png') $newsrc .= '.png';

        $newtag = str_replace($match[1], $newsrc, $match[0]);
        $linktag = '_Abbildung ' . ($k + 1) . ': ' . substr($newtag, 1) . '_';
        $content = str_replace($match[0], $newtag . PHP_EOL . PHP_EOL . $linktag, $content);
    }
    return $content;
}

function listFriends()
{
    return array_merge(getFriends(), getTeam());
}

$app = new Silex\Application();
$app['debug'] = true;

$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__ . '/templates',
    'twig.class_path' => __DIR__ . '/../vendor/twig/lib',
));
$app->register(new Silex\Provider\SessionServiceProvider());

$getFile = function($filename) use($app)
{
    $files = listFiles();
    $encFilename = rawurlencode($filename);
    $file = array_filter($files, function($item) use($encFilename)
    {
        return $item['file'] == $encFilename;
    });
    if (empty($file)) $app->abort(404, 'The file ' . $filename . ' could not be found.');
    $file = array_pop($file);
    if (!is_file(DROPBOX . urldecode($file['file']))) $app->abort(404, 'Das Dokument wurde gelöscht.');
    return $file;
};

$friends = listFriends();
$team = getTeam();
$app->before(function () use ($app, $friends, $team)
{
    $app['session']->set('authenticated', $app['session']->get('username') !== null);
    $app['session']->set('vip', in_array(strtolower($app['session']->get('username')), $friends));
    $app['session']->set('admin', in_array(strtolower($app['session']->get('username')), $team));

    $app['twig']->addGlobal('username', $app['session']->get('username'));
    $app['twig']->addGlobal('name', $app['session']->get('name'));
    $app['twig']->addGlobal('vip', $app['session']->get('vip'));
    $app['twig']->addGlobal('admin', $app['session']->get('admin'));
    $app['twig']->addGlobal('DEV', DEV);
});

$app->error(function (\Exception $e, $code) use($app)
{
    return new Symfony\Component\HttpFoundation\Response(
        $app['twig']->render('error.twig', array('message' => $e->getMessage(), 'code' => $code)),
        $code
    );
});

$app->get('/', function() use($app)
{
    return $app['twig']->render('home.twig', array('files' => $app['session']->get('vip') ? listDocuments() : array()));
});

$app->get('/reload', function() use($app)
{
    apc_delete('files-ttl');
    apc_delete('friends-ttl');
    return $app->redirect('/');
});

$app->get('/file/{filename}', function($filename) use($app, $getFile)
{
    if (!$app['session']->get('authenticated')) $app->abort(403, 'Not authenticated.');
    if (!$app['session']->get('vip')) $app->abort(403, 'You are not my friend.');
    $file = $getFile($filename);
    if ($file['type'] == 'mp4' && $app['request']->get('dl') != '1') {
        return $app['twig']->render('video.twig', array('files' => listDocuments(), 'file' => $file));
    } elseif ($file['type'] !== 'txt') {
        $stream = function () use ($file)
        {
            readfile(DROPBOX . urldecode($file['file']));
        };
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($fi, DROPBOX . urldecode($file['file']));
        finfo_close($fi);
        return $app->stream($stream, 200, array('Content-Type' => $mime));
    } else {
        $content = file_get_contents(DROPBOX . urldecode($file['file']));
        $content = mediaFilter($content);
        $content = dotFilter($content);
        preg_match_all('/^(#+) ([^\n\r]+)/m', $content, $matches, PREG_SET_ORDER);
        $md = Markdown($content);
        $structure = array();
        foreach ($matches as $match) {
            $lvl = strlen($match[1]);
            if ($lvl != 2) continue;
            $h = 'h' . $lvl;
            $id = preg_replace('/[^0-9a-z]/', '', strtolower($match[2]));
            $structure[] = array(
                'id' => $id,
                'label' => $match[2],
            );
            $md = str_replace('<' . $h . '>' . $match[2] . '</' . $h . '>', '<' . $h . ' id="' . $id . '">' . $match[2] . '</' . $h . '>', $md);
        }
        return $app['twig']->render('file.twig', array('files' => listDocuments(), 'file' => $file, 'content' => $md, 'structure' => $structure));
    }
});

$app->get('/file', function() use($app, $getFile)
{
    if (!$app['session']->get('authenticated')) $app->abort(403, 'Not authenticated.');
    if (!$app['session']->get('vip')) $app->abort(403, 'You are not my friend.');
    $file = $getFile($app['request']->get('file'));
    $stream = function () use ($file)
    {
        readfile(DROPBOX . urldecode($file['file']));
    };
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($fi, DROPBOX . urldecode($file['file']));
    finfo_close($fi);
    return $app->stream($stream, 200, array('Content-Type' => $mime));
});

$app->get('/dot', function() use($app)
{
    $data = $app['request']->get('data');
    $outfile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . sha1($data) . '.png';
    if (!file_exists($outfile)) {
        $descriptorspec = array(
            0 => array('pipe', 'r'),
            2 => array('pipe', 'w'),
        );
        $renderer = 'dot';
        if (stristr($data, 'neato')) $renderer = 'neato';
        $cmd = "`which env` {$renderer} -Tpng";
        $cmd .= ' -o ' . $outfile;
        $process = proc_open($cmd, $descriptorspec, $pipes, sys_get_temp_dir());

        fwrite($pipes[0], $data);
        fclose($pipes[0]);
        $dotErrors = '';
        while (!feof($pipes[2])) {
            $dotErrors .= fgets($pipes[2]);
        }
        fclose($pipes[2]);
        $dotCode = proc_close($process);
        if ($dotCode != 0) $app->abort($dotErrors . ' (' . $dotCode . ')');
    }
    $stream = function () use ($outfile)
    {
        readfile($outfile);
    };
    return $app->stream($stream, 200, array('Content-Type' => 'image/png'));
});

$app->get('/editor', function() use($app, $getFile)
{
    if (!$app['session']->get('authenticated')) $app->abort(403, 'Not authenticated.');
    if (!$app['session']->get('admin')) $app->abort(403, 'You are not and admin.');
    $file = $getFile($app['request']->get('file'));
    $contents = file_get_contents(DROPBOX . urldecode($file['file']));
    return $app['twig']->render('editor.twig', array('file' => $file, 'contents' => $contents, 'markdown' => Markdown(dotFilter(mediaFilter($contents)))));
});

$app->post('/editor', function() use($app, $getFile)
{
    if (!$app['session']->get('authenticated')) $app->abort(403, 'Not authenticated.');
    if (!$app['session']->get('admin')) $app->abort(403, 'You are not and admin.');
    $file = $getFile($app['request']->get('file'));
    $contents = $app['request']->get('content');
    file_put_contents(DROPBOX . urldecode($file['file']), $contents);
    return $app['twig']->render('editor.twig', array('file' => $file, 'contents' => $contents, 'markdown' => Markdown(dotFilter(mediaFilter($contents)))));
});

$app->get('/login', function () use ($app)
{
    // check if the user is already logged-in
    if (null !== ($username = $app['session']->get('username'))) {
        return $app->redirect('/');
    }

    $oauth = new OAuth(CONS_KEY, CONS_SECRET, OAUTH_SIG_METHOD_HMACSHA1, OAUTH_AUTH_TYPE_URI);
    $request_token = $oauth->getRequestToken('https://twitter.com/oauth/request_token');

    $app['session']->set('secret', $request_token['oauth_token_secret']);

    return $app->redirect('https://twitter.com/oauth/authenticate?oauth_token=' . $request_token['oauth_token']);
});

$app->get('/logout', function () use ($app)
{
    $app['session']->invalidate();
    return $app->redirect('/');
});

$app->get('/oauth', function() use ($app)
{
    // check if the user is already logged-in
    if (null !== ($username = $app['session']->get('username'))) {
        return $app->redirect('/');
    }

    $oauth_token = $app['request']->get('oauth_token');

    if ($oauth_token == null) {
        $app->abort(400, 'Invalid token');
    }

    $secret = $app['session']->get('secret');

    $oauth = new OAuth(CONS_KEY, CONS_SECRET, OAUTH_SIG_METHOD_HMACSHA1, OAUTH_AUTH_TYPE_URI);
    $oauth->setToken($oauth_token, $secret);

    try {
        $oauth_token_info = $oauth->getAccessToken('https://twitter.com/oauth/access_token');
        // retrieve Twitter user details
        $oauth->setToken($oauth_token_info['oauth_token'], $oauth_token_info['oauth_token_secret']);
        $oauth->fetch('https://twitter.com/account/verify_credentials.json');
        $json = json_decode($oauth->getLastResponse());
        $app['session']->set('username', $json->screen_name);
        $app['session']->set('name', $json->name);

        // Mail me
        if (!in_array($json->screen_name, array('markustacker', 'retext', 'coderbyheart'))) {
            $msg = sprintf('Login to %s from @%s', $_SERVER['HTTP_HOST'], $json->screen_name);
            mail('m@retext.it', $msg, $msg);
        }

        return $app->redirect('/');
    } catch (OAuthException $e) {
        $app->abort(401, $e->getMessage());
    }
});

$app->run();
