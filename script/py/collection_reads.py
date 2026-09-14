"""Coordinate destructive image replacement with cooperating long-running readers.

Separate from archive.lock: adding a new gallery does not block existing readers.
Lock file remains in cache/, outside the images tree replaced by import/purge.
"""
import os,fcntl,functools
from storage import data_path

def acquire_read_guard(*, exclusive=False):
    fd=os.open(data_path('cache/collection-read.lock'),os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
    try:fcntl.flock(fd,(fcntl.LOCK_EX if exclusive else fcntl.LOCK_SH)|fcntl.LOCK_NB)
    except BaseException:os.close(fd);raise
    return fd

def collection_reader(function):
    @functools.wraps(function)
    def guarded(*args,**kwargs):
        fd=acquire_read_guard()
        try:return function(*args,**kwargs)
        finally:os.close(fd)
    return guarded
