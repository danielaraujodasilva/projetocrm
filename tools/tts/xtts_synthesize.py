import argparse
import json
import os
import sys
from pathlib import Path


def main() -> int:
    parser = argparse.ArgumentParser(description="Generate speech with Coqui XTTS v2.")
    parser.add_argument("--text", required=True, help="Text to synthesize.")
    parser.add_argument("--speaker-wav", required=True, help="Reference voice sample path.")
    parser.add_argument("--out", required=True, help="Output WAV path.")
    parser.add_argument("--language", default="pt", help="XTTS language code, e.g. pt, en, es.")
    parser.add_argument(
        "--model-name",
        default="tts_models/multilingual/multi-dataset/xtts_v2",
        help="Coqui TTS model name.",
    )
    args = parser.parse_args()

    text = " ".join(args.text.strip().split())
    speaker = Path(args.speaker_wav)
    output = Path(args.out)
    if not text:
        print(json.dumps({"ok": False, "error": "empty_text"}, ensure_ascii=False))
        return 2
    if not speaker.is_file():
        print(json.dumps({"ok": False, "error": "speaker_wav_not_found"}, ensure_ascii=False))
        return 3

    output.parent.mkdir(parents=True, exist_ok=True)

    # Avoid an interactive license prompt in unattended WhatsApp workers.
    os.environ.setdefault("COQUI_TOS_AGREED", "1")
    os.environ.setdefault("TTS_HOME", r"C:\AI\xtts\models")

    try:
        from TTS.api import TTS

        tts = TTS(args.model_name)
        tts.tts_to_file(
            text=text,
            speaker_wav=str(speaker),
            language=args.language,
            file_path=str(output),
        )
    except Exception as exc:
        print(json.dumps({"ok": False, "error": str(exc)[:800]}, ensure_ascii=False))
        return 1

    if not output.is_file() or output.stat().st_size < 1024:
        print(json.dumps({"ok": False, "error": "output_not_created"}, ensure_ascii=False))
        return 4

    print(json.dumps({"ok": True, "output": str(output)}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())
