import json
import os
import sys
import traceback


PROGRESS_PATH = ""

try:
    sys.stdout.reconfigure(encoding="utf-8")
    sys.stderr.reconfigure(encoding="utf-8")
except Exception:
    pass


def emit(payload):
    line = json.dumps(payload, ensure_ascii=False)
    print(line, flush=True)
    if PROGRESS_PATH:
        with open(PROGRESS_PATH, "a", encoding="utf-8") as handle:
            handle.write(line + "\n")
            handle.flush()


def load_manifest(path):
    with open(path, "r", encoding="utf-8") as handle:
        payload = json.load(handle)
    return [item for item in payload if isinstance(item, dict) and os.path.isfile(item.get("path", ""))]


def main():
    global PROGRESS_PATH
    if len(sys.argv) < 2:
        emit({"type": "fatal", "error": "Manifesto de audios nao informado."})
        return 1

    manifest_path = sys.argv[1]
    model_name = sys.argv[2] if len(sys.argv) > 2 else "small"
    engine = (sys.argv[3] if len(sys.argv) > 3 else "auto").lower()
    PROGRESS_PATH = sys.argv[4] if len(sys.argv) > 4 else ""
    items = load_manifest(manifest_path)
    if not items:
        emit({"type": "complete", "total": 0, "completed": 0})
        return 0

    prompt = (
        "Transcricao em portugues brasileiro de conversa de WhatsApp. "
        "Contexto: atendimento de estudio de tatuagem, orcamento, agenda, tattoo, desenho, cliente."
    )
    selected_engine = ""
    model = None
    errors = []

    if engine in {"auto", "openai"}:
        try:
            import whisper

            emit({"type": "loading", "engine": "openai", "total": len(items)})
            model = whisper.load_model(model_name)
            selected_engine = "openai"
        except Exception as exc:
            errors.append("openai-whisper: " + str(exc))
            if engine == "openai":
                emit({"type": "fatal", "error": errors[-1]})
                return 1

    if model is None and engine in {"auto", "faster"}:
        try:
            from faster_whisper import WhisperModel

            emit({"type": "loading", "engine": "faster", "total": len(items)})
            model = WhisperModel(model_name, device="cpu", compute_type="int8")
            selected_engine = "faster"
        except Exception as exc:
            errors.append("faster-whisper: " + str(exc))

    if model is None:
        emit({"type": "fatal", "error": "; ".join(errors) or "Nenhum transcritor disponivel."})
        return 1

    emit({"type": "ready", "engine": selected_engine, "total": len(items)})
    completed = 0
    for index, item in enumerate(items, start=1):
        key = str(item.get("key", ""))
        path = str(item.get("path", ""))
        emit({"type": "item_start", "key": key, "index": index, "total": len(items)})
        try:
            if selected_engine == "openai":
                result = model.transcribe(
                    path,
                    language="pt",
                    task="transcribe",
                    fp16=False,
                    temperature=0,
                    beam_size=5,
                    best_of=5,
                    condition_on_previous_text=False,
                    initial_prompt=prompt,
                    no_speech_threshold=0.2,
                    logprob_threshold=-1.0,
                )
                text = (result.get("text") or "").strip()
            else:
                segments, _ = model.transcribe(
                    path,
                    language="pt",
                    beam_size=5,
                    vad_filter=False,
                    condition_on_previous_text=False,
                    initial_prompt=prompt,
                )
                text = " ".join(segment.text.strip() for segment in segments).strip()
            completed += 1
            emit({
                "type": "item",
                "ok": bool(text),
                "key": key,
                "index": index,
                "total": len(items),
                "text": text,
                "error": "" if text else "Nenhuma fala reconhecida.",
            })
        except Exception as exc:
            completed += 1
            emit({
                "type": "item",
                "ok": False,
                "key": key,
                "index": index,
                "total": len(items),
                "text": "",
                "error": str(exc),
            })

    emit({"type": "complete", "total": len(items), "completed": completed, "engine": selected_engine})
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        emit({"type": "fatal", "error": str(exc), "trace": traceback.format_exc(limit=3)})
        raise SystemExit(1)
