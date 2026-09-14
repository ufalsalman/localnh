"""One archive writer per data root, shared by web/CLI; bounded child lifetime."""
import fcntl, json, os, resource, shutil, signal, sqlite3, subprocess, sys, time
from storage import data_path, SOURCE


def acquire(inherited=None):
    path = data_path('cache/archive.lock')
    if inherited is None:
        fd = os.open(path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    else:
        fd = inherited
        if (os.fstat(fd).st_dev, os.fstat(fd).st_ino) != (path.stat().st_dev, path.stat().st_ino):
            raise ValueError('Invalid inherited archive lock')
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BaseException:
        if inherited is None: os.close(fd)
        raise
    return fd


def retry_remaining():
    path = data_path('cache/archive-retry.json')
    if not path.exists(): return 0
    return max(0, int(json.loads(path.read_text())['until']) - int(time.time()))


def defer_upstream(value):
    from email.utils import parsedate_to_datetime
    try:
        seconds = int(value)
    except (TypeError, ValueError):
        try: seconds = int(parsedate_to_datetime(value).timestamp() - time.time())
        except (TypeError, ValueError, OverflowError): seconds = 60
    seconds = max(1, seconds)
    path = data_path('cache/archive-retry.json')
    temporary = data_path('cache/archive-retry.part')
    temporary.write_text(json.dumps({'until': int(time.time()) + seconds}))
    os.replace(temporary, path)
    return seconds


def emit_error(message):
    print(json.dumps({'error': message}), flush=True)


def run(code, fix=False, inherited=None):
    if not code.isascii() or not code.isdigit() or len(code) > 20:
        emit_error('Invalid gallery ID'); return 1
    try:
        fd = acquire(inherited)
    except BlockingIOError:
        emit_error('An archive, deletion or snapshot is active. Retry shortly.'); return 75
    stage = data_path('cache') / '.archive-work'
    child = None
    def terminate(*_): raise SystemExit(143)
    signal.signal(signal.SIGTERM, terminate)
    signal.signal(signal.SIGINT, terminate)
    try:
        # Recheck after admission; duplicate requests never start another fetch.
        with sqlite3.connect(data_path('cache.sqlite')) as db:
            db.execute('CREATE TABLE IF NOT EXISTS galleries (id TEXT PRIMARY KEY, title TEXT, title_jp TEXT, language TEXT, scanlator TEXT, tags TEXT, cover_path TEXT, pages_paths TEXT, page_count INTEGER, upload_date INTEGER, created_at INTEGER, raw_api_data TEXT)')
            exists = db.execute('SELECT 1 FROM galleries WHERE id=?', (code,)).fetchone()
        if exists and not fix:
            print(json.dumps({'type':'done','data':{'id':code},'existing':True}), flush=True)
            return 0
        retry = retry_remaining()
        if retry:
            emit_error(f'upstream is limiting archive requests (http 429). retry in {retry} seconds.'); return 75
        if stage.is_symlink(): raise ValueError('Linked archive workspace')
        if stage.exists(): shutil.rmtree(stage)
        stage.mkdir(mode=0o700)
        parent_pid = os.getpid()
        def limits():
            # A killed supervisor must not leave an unsupervised downloader.
            import ctypes
            if ctypes.CDLL(None).prctl(1, signal.SIGKILL) != 0 or os.getppid() != parent_pid:
                os._exit(1)
            resource.setrlimit(resource.RLIMIT_AS, (805306368, 805306368))
            resource.setrlimit(resource.RLIMIT_FSIZE, (33554432, 33554432))
            resource.setrlimit(resource.RLIMIT_CPU, (900, 900))
            resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
        command=[sys.executable, str(SOURCE / 'script/py/fetch.py'),code,'--archive-worker']
        if fix: command.append('--fix')
        child=subprocess.Popen(command, start_new_session=True, pass_fds=(fd,), preexec_fn=limits,
                               env=dict(os.environ,LOCALNH_ARCHIVE_FD=str(fd), LOCALNH_ARCHIVE_WEB="1" if inherited is not None else "0"))
        try: return child.wait(timeout=900)
        except subprocess.TimeoutExpired:
            emit_error('Archive exceeded the 900-second deadline. Retry or use a smaller gallery.'); return 124
    finally:
        if child is not None:
            try: os.killpg(child.pid, signal.SIGKILL)
            except ProcessLookupError: pass
            child.wait()
        # Never remove a gallery directory: only this exclusively-owned workspace.
        if stage.exists() and not stage.is_symlink(): shutil.rmtree(stage)
        os.close(fd)
