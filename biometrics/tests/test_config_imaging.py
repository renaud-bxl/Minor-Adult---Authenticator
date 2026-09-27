"""Configuration (boucle locale imposée) et décodage des images (types, tailles, bombes)."""
import base64
import struct
import zlib

import cv2
import numpy as np
import pytest

from veriage_biometrics import imaging
from veriage_biometrics.config import ConfigError, Settings


def test_settings_require_loopback_and_long_secret():
    ok = Settings.from_env({"BIOMETRICS_SECRET": "x" * 32})
    assert ok.host == "127.0.0.1" and ok.port == 8765
    assert Settings.from_env({"BIOMETRICS_SECRET": "x" * 32, "BIOMETRICS_HOST": "::1"}).host == "::1"
    for env in ({"BIOMETRICS_SECRET": "court"},
                {"BIOMETRICS_SECRET": "x" * 32, "BIOMETRICS_HOST": "0.0.0.0"},
                {"BIOMETRICS_SECRET": "x" * 32, "BIOMETRICS_HOST": "192.168.1.10"},
                {"BIOMETRICS_SECRET": "x" * 32, "BIOMETRICS_HOST": "localhost.evil"},
                {"BIOMETRICS_SECRET": "x" * 32, "BIOMETRICS_PORT": "80"}):
        with pytest.raises(ConfigError):
            Settings.from_env(env)


def _jpeg(w=320, h=240):
    ok, buf = cv2.imencode(".jpg", np.full((h, w, 3), 128, np.uint8))
    return buf.tobytes()


def test_decode_jpeg_and_png_in_memory():
    assert imaging.decode(_jpeg(), 1000).shape == (240, 320, 3)
    ok, png = cv2.imencode(".png", np.zeros((100, 120, 3), np.uint8))
    assert imaging.image_size(png.tobytes()) == ("png", 120, 100)


@pytest.mark.parametrize("data,reason", [
    (b"GIF89a" + b"\x00" * 100, "image_invalid_type"),
    (b"<svg xmlns='http://www.w3.org/2000/svg'/>", "image_invalid_type"),
    (b"\xff\xd8\xff\xe0\x00\x10JFIF" + b"\x00" * 10, "image_invalid"),
])
def test_unsupported_or_corrupt_images(data, reason):
    with pytest.raises(imaging.ImageError) as error:
        imaging.decode(data, 4096)
    assert error.value.reason == reason


def test_dimensions_are_checked_before_decoding():
    """Bombe de décompression : un PNG qui annonce 60 000 × 60 000 pixels est refusé sur son en-tête."""
    ihdr = struct.pack(">IIBBBBB", 60000, 60000, 8, 2, 0, 0, 0)
    chunk = b"IHDR" + ihdr
    bomb = imaging.PNG_MAGIC + struct.pack(">I", len(ihdr)) + chunk + struct.pack(">I", zlib.crc32(chunk))
    with pytest.raises(imaging.ImageError) as error:
        imaging.decode(bomb, 4096)
    assert error.value.reason == "image_too_large"
    with pytest.raises(imaging.ImageError) as error:
        imaging.decode(_jpeg(40, 40), 4096)
    assert error.value.reason == "image_too_small"


def test_base64_is_strict_and_bounded():
    assert imaging.b64decode(base64.b64encode(b"abc").decode(), 10) == b"abc"
    for value, reason in (("", "image_missing"), (None, "image_missing"), ("@@@", "image_invalid"),
                          (base64.b64encode(b"x" * 100).decode(), "image_too_large")):
        with pytest.raises(imaging.ImageError) as error:
            imaging.b64decode(value, 10)
        assert error.value.reason == reason
