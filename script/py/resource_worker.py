"""Web-only job supervisor. CLI exports retain their existing behavior.

No shell is involved. Children get independent process groups and hard address
space/file size/CPU limits; timeout and termination reap the entire group.
"""
import json, os, resource, signal, subprocess, sys


def main():
    spec = json.load(sys.stdin)
    child = None
    parent_pid = os.getpid()
    lock_fds = spec.get("lock_fds", [])
    if not isinstance(lock_fds, list) or any(type(fd) is not int or fd < 3 for fd in lock_fds):
        raise ValueError("Invalid inherited lock descriptors")
    for fd in lock_fds: os.fstat(fd)

    def stop(*_):
        if child is not None:
            try:
                os.killpg(child.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
        raise SystemExit(143)

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)

    def limits():
        # Linux parent-death signal also covers uncatchable supervisor SIGKILL.
        # Recheck PPID after registration to close the parent-death race.
        import ctypes
        if ctypes.CDLL(None).prctl(1, signal.SIGKILL) != 0 or os.getppid() != parent_pid:
            os._exit(1)
        resource.setrlimit(resource.RLIMIT_AS, (spec['memory'], spec['memory']))
        resource.setrlimit(resource.RLIMIT_FSIZE, (spec['bytes'], spec['bytes']))
        resource.setrlimit(resource.RLIMIT_CPU, (spec['seconds'], spec['seconds']))
        resource.setrlimit(resource.RLIMIT_CORE, (0, 0))

    try:
        with open(spec.get('input') or os.devnull, 'rb') as src, open(spec.get('output') or os.devnull, 'wb') as dst:
            child = subprocess.Popen(spec['command'], stdin=src, stdout=dst, stderr=subprocess.DEVNULL,
                                     start_new_session=True, preexec_fn=limits, pass_fds=tuple(lock_fds),
                                     env=dict(os.environ, VIPS_CONCURRENCY='1'))
            return child.wait(timeout=spec['seconds'])
    except subprocess.TimeoutExpired:
        return 124
    finally:
        if child is not None:
            try:
                os.killpg(child.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            child.wait()


if __name__ == '__main__':
    sys.exit(main())
