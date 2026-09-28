#!/usr/bin/env python3
"""Génère des images de TEST synthétiques (document fictif « Utopie » + séquence de selfie simulée)
pour le test d'intégration PHP → Python et l'E2E. Outil de test uniquement, jamais utilisé en production.

    .venv/bin/python scripts/make_test_images.py --out DOSSIER --challenge turn_left,blink,turn_right
        [--birth 2000-03-14] [--expiry 2031-05-20] [--document-face astronaut.png] [--selfie-face astronaut.png]
        [--passport] [--y4m selfie.y4m] [--card-video card.y4m]

Écrit : front.jpg, back.jpg (sauf --passport), frames/NNN_step_t.jpg, manifest.json (défi, horodatages).
--y4m : vidéo brute (format YUV4MPEG2) d'un visage pour la fausse caméra de Chromium (E2E).
Visages : photos du domaine public de tests/assets (scripts/fetch_test_assets.sh).
"""
from __future__ import annotations

import argparse
import datetime as dt
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path[:0] = [str(ROOT), str(ROOT / "tests")]

import cv2  # noqa: E402
import numpy as np  # noqa: E402

import synth  # noqa: E402
from veriage_biometrics.faces import FaceEngine  # noqa: E402
from veriage_biometrics.liveness import Landmarker  # noqa: E402


def landmarks(landmarker: Landmarker, image: np.ndarray) -> np.ndarray:
    import mediapipe as mp
    res = landmarker._landmarker.detect(mp.Image(image_format=mp.ImageFormat.SRGB, data=np.ascontiguousarray(image[:, :, ::-1])))
    h, w = image.shape[:2]
    return np.array([[p.x * w, p.y * h] for p in res.face_landmarks[0]], dtype=np.float32)


def write_y4m(path: Path, frames: list[np.ndarray], fps: int = 25) -> None:
    """YUV4MPEG2 4:2:0 (format lu par --use-file-for-fake-video-capture)."""
    h, w = frames[0].shape[:2]
    with path.open("wb") as out:
        out.write(f"YUV4MPEG2 W{w} H{h} F{fps}:1 Ip A1:1 C420jpeg\n".encode())
        for frame in frames:
            yuv = cv2.cvtColor(frame, cv2.COLOR_BGR2YUV_I420)
            out.write(b"FRAME\n")
            out.write(yuv.tobytes())


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--out", required=True)
    parser.add_argument("--challenge", default="turn_left,open_mouth,blink,turn_right")
    parser.add_argument("--birth", default="2000-03-14")
    parser.add_argument("--expiry", default="2031-05-20")
    parser.add_argument("--number", default="UT1234567")
    parser.add_argument("--document-face", default="obama2.jpg")
    parser.add_argument("--selfie-face", default="obama.jpg", help="AUTRE photo de la personne (un selfie identique au portrait est refusé)")
    parser.add_argument("--back-of", help="attaque B : verso d'une AUTRE pièce, « numéro,naissance,expiration »")
    parser.add_argument("--front-selfie", action="store_true", help="attaque A : un selfie envoyé à la place du recto")
    parser.add_argument("--passport", action="store_true")
    parser.add_argument("--step-ms", type=int, default=3000)
    parser.add_argument("--interval-ms", type=int, default=200)
    parser.add_argument("--neutral-frames", type=int, default=7)
    parser.add_argument("--y4m", help="vidéo d'un visage immobile pour la fausse caméra (E2E)")
    parser.add_argument("--card-video", help="vidéo du recto de la carte pour la fausse caméra (E2E)")
    parser.add_argument("--camera-frames", help="dossier : poses du visage (neutre, rotations, yeux fermés, bouche ouverte) pour la caméra simulée de l'E2E")
    parser.add_argument("--doc-poses", help="dossier : mêmes poses, tirées du PORTRAIT DU DOCUMENT (attaque C, doit échouer)")
    args = parser.parse_args()

    out = Path(args.out)
    (out / "frames").mkdir(parents=True, exist_ok=True)
    birth, expiry = dt.date.fromisoformat(args.birth), dt.date.fromisoformat(args.expiry)
    doc_face = synth.load_asset(args.document_face)
    if args.passport:
        (out / "front.jpg").write_bytes(synth.jpeg(synth.passport(doc_face, args.number, birth, expiry)))
    else:
        front, back = synth.identity_card(doc_face, args.number, birth, expiry)
        if args.back_of:
            number, other_birth, other_expiry = args.back_of.split(",")
            _, back = synth.identity_card(doc_face, number, dt.date.fromisoformat(other_birth), dt.date.fromisoformat(other_expiry))
        if args.front_selfie:
            front = synth.Image.fromarray(cv2.cvtColor(synth.selfie_frame(synth.load_asset(args.selfie_face)), cv2.COLOR_BGR2RGB))
        (out / "front.jpg").write_bytes(synth.jpeg(front))
        (out / "back.jpg").write_bytes(synth.jpeg(back))

    models = ROOT / "models"
    engine = FaceEngine(models)
    landmarker = Landmarker(models)
    base = cv2.resize(synth.selfie_frame(synth.load_asset(args.selfie_face)), (480, 360), interpolation=cv2.INTER_AREA)
    box = tuple(int(v) for v in engine.detect(base)[0].box)
    points = landmarks(landmarker, base)
    challenge = [c for c in args.challenge.split(",") if c]
    frames = synth.selfie_sequence(base, challenge, points, box, step_ms=args.step_ms, interval_ms=args.interval_ms,
                                   neutral_frames=args.neutral_frames, quality=80)
    manifest = {"challenge": challenge, "frames": []}
    for i, frame in enumerate(frames):
        name = f"{i:03d}_{frame['step']}_{frame['t']}.jpg"
        (out / "frames" / name).write_bytes(frame["jpeg"])
        manifest["frames"].append({"file": name, "t": frame["t"], "step": frame["step"]})
    (out / "manifest.json").write_text(json.dumps(manifest, indent=1))

    if args.y4m:
        still = synth.selfie_frame(synth.load_asset(args.selfie_face))
        write_y4m(Path(args.y4m), [still] * 25)
    if args.card_video:
        card = cv2.cvtColor(np.asarray(synth.scene(synth.card_front(doc_face, args.number, birth, expiry), angle=-2, size=(1280, 960))), cv2.COLOR_RGB2BGR)
        write_y4m(Path(args.card_video), [card] * 25)
    if args.camera_frames:
        poses = Path(args.camera_frames)
        poses.mkdir(parents=True, exist_ok=True)
        still = synth.selfie_frame(synth.load_asset(args.selfie_face))
        still_box = tuple(int(v) for v in engine.detect(still)[0].box)
        still_points = landmarks(landmarker, still)
        cv2.imwrite(str(poses / "neutral.jpg"), still, [cv2.IMWRITE_JPEG_QUALITY, 85])
        cv2.imwrite(str(poses / "closed.jpg"), synth.close_eyes(still, still_points), [cv2.IMWRITE_JPEG_QUALITY, 85])
        cv2.imwrite(str(poses / "mouth.jpg"), synth.open_mouth(still, still_points), [cv2.IMWRITE_JPEG_QUALITY, 85])
        for i, strength in enumerate((0.35, 0.7, 1.0, 1.3, 1.5), start=1):
            cv2.imwrite(str(poses / f"left_{i}.jpg"), synth.yaw_warp(still, still_box, strength), [cv2.IMWRITE_JPEG_QUALITY, 85])
            cv2.imwrite(str(poses / f"right_{i}.jpg"), synth.yaw_warp(still, still_box, -strength), [cv2.IMWRITE_JPEG_QUALITY, 85])
    if args.doc_poses:
        # Portrait imprimé sur la carte, recentré dans un cadre de webcam (attaque C du critique).
        card = cv2.cvtColor(np.asarray(synth.card_front(doc_face, args.number, birth, expiry)), cv2.COLOR_RGB2BGR)
        fake = np.full((480, 640, 3), 120, np.uint8)
        fake[10:470, 140:500] = cv2.resize(card[200:546, 45:315], (360, 460))
        poses = Path(args.doc_poses)
        poses.mkdir(parents=True, exist_ok=True)
        fake_box = tuple(int(v) for v in engine.detect(fake)[0].box)
        fake_points = landmarks(landmarker, fake)
        cv2.imwrite(str(poses / "neutral.jpg"), fake, [cv2.IMWRITE_JPEG_QUALITY, 85])
        cv2.imwrite(str(poses / "closed.jpg"), synth.close_eyes(fake, fake_points), [cv2.IMWRITE_JPEG_QUALITY, 85])
        cv2.imwrite(str(poses / "mouth.jpg"), synth.open_mouth(fake, fake_points), [cv2.IMWRITE_JPEG_QUALITY, 85])
        for i, strength in enumerate((0.35, 0.7, 1.0, 1.3, 1.5), start=1):
            cv2.imwrite(str(poses / f"left_{i}.jpg"), synth.yaw_warp(fake, fake_box, strength), [cv2.IMWRITE_JPEG_QUALITY, 85])
            cv2.imwrite(str(poses / f"right_{i}.jpg"), synth.yaw_warp(fake, fake_box, -strength), [cv2.IMWRITE_JPEG_QUALITY, 85])
    landmarker.close()
    print(json.dumps({"frames": len(frames), "challenge": challenge}))
    return 0


if __name__ == "__main__":
    sys.exit(main())
