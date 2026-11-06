"""Decode a synthetic local QR PNG with the system libzbar; requires Pillow only for QA, never runtime."""
import ctypes
import ctypes.util
import sys
from PIL import Image


def decode(filename):
  """Return decoded QR payloads from one local image; raise on missing decoder or failed scanning."""
  library = ctypes.util.find_library('zbar')
  if not library:
    raise RuntimeError('Independent libzbar decoder unavailable')
  api = ctypes.CDLL(library)
  pointer = ctypes.c_void_p
  signatures = {
    'zbar_image_scanner_create': (pointer, []),
    'zbar_image_scanner_destroy': (None, [pointer]),
    'zbar_image_scanner_set_config': (ctypes.c_int, [pointer, ctypes.c_int, ctypes.c_int, ctypes.c_int]),
    'zbar_image_create': (pointer, []),
    'zbar_image_destroy': (None, [pointer]),
    'zbar_image_set_format': (None, [pointer, ctypes.c_ulong]),
    'zbar_image_set_size': (None, [pointer, ctypes.c_uint, ctypes.c_uint]),
    'zbar_image_set_data': (None, [pointer, pointer, ctypes.c_ulong, pointer]),
    'zbar_scan_image': (ctypes.c_int, [pointer, pointer]),
    'zbar_image_first_symbol': (pointer, [pointer]),
    'zbar_symbol_next': (pointer, [pointer]),
    'zbar_symbol_get_data': (ctypes.c_char_p, [pointer])
  }
  for name, (result, arguments) in signatures.items():
    function = getattr(api, name)
    function.restype = result
    function.argtypes = arguments
  source = Image.open(filename).convert('L')
  pixels = ctypes.create_string_buffer(source.tobytes())
  scanner = api.zbar_image_scanner_create()
  image = api.zbar_image_create()
  try:
    api.zbar_image_scanner_set_config(scanner, 0, 0, 0)
    api.zbar_image_scanner_set_config(scanner, 64, 0, 1)
    api.zbar_image_set_format(image, 0x30303859)  # Y800 grayscale, no color interpretation.
    api.zbar_image_set_size(image, *source.size)
    api.zbar_image_set_data(image, pixels, len(source.tobytes()), None)
    if api.zbar_scan_image(scanner, image) < 1:
      raise RuntimeError('No independent QR decoding result')
    result = []
    symbol = api.zbar_image_first_symbol(image)
    while symbol:
      result.append(api.zbar_symbol_get_data(symbol).decode('ascii'))
      symbol = api.zbar_symbol_next(symbol)
    return result
  finally:
    api.zbar_image_destroy(image)
    api.zbar_image_scanner_destroy(scanner)


if __name__ == '__main__':
  if len(sys.argv) != 2:
    raise SystemExit('Usage: decode-users-qr.py synthetic.png')
  for value in decode(sys.argv[1]):
    print(value)
