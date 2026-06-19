# Just a small helper script for easyIPSW to extract AEAs using an existing library.

import aea
import base64
import os
import sys
import get_key

class ProgressReader:
  def __init__(self, file, callback):
    self.file = file
    self.callback = callback
    self.current = 0

  def read(self, size):
    chunk = self.file.read(size)
    self.current += len(chunk)
    self.callback(self.current)
    return chunk

  def tell(self):
    return self.file.tell()

  def seek(self, pos):
    return self.file.seek(pos)

inPath = sys.argv[1]
outPath = sys.argv[2]

with open(inPath, "rb") as inFile, open(outPath, "wb") as outFile:
  def print_progress(current):
    print(current)
  
  key = get_key.get_key(inFile)
  inFile.seek(0)
  aea.decode_stream(ProgressReader(inFile, print_progress), outFile, symmetric_key=base64.b64decode(key))