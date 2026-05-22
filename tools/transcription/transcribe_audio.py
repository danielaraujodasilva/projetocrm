import json
import os
import sys
import traceback

try:
    sys.stdout.reconfigure(encoding="utf-8")
    sys.stderr.reconfigure(encoding="utf-8")
except Exception:
    pass


def emit_json(payload):
    data = json.dumps(payload, ensure_ascii=False)
    try:
        sys.stdout.write(data)
        sys.stdout.flush()
    except UnicodeEncodeError:
        sys.stdout.buffer.write(data.encode("utf-8"))
        sys.stdout.buffer.flush()


def log_debug(message):
    try:
        log_path = os.path.join(os.path.dirname(os.path.dirname(__file__)), "data", "python_whisper_debug.log")
        os.makedirs(os.path.dirname(log_path), exist_ok=True)
        with open(log_path, "a", encoding="utf-8") as fh:
            fh.write(message + "\n")
    except Exception:
        pass


def main():
    if len(sys.argv) < 2:
        print(json.dumps({"ok": False, "error": "Arquivo de audio nao informado"}))
        return 1

    audio_path = sys.argv[1]
    model_name = sys.argv[2] if len(sys.argv) > 2 else "base"
    engine = (sys.argv[3] if len(sys.argv) > 3 else "auto").lower()
    log_debug(f"start audio={audio_path} model={model_name} engine={engine} python={sys.executable}")

    engines = [engine] if engine in {"openai", "faster"} else ["openai", "faster"]
    prompt = (
        "Transcricao em portugues brasileiro de conversa de WhatsApp. "
        "Contexto: atendimento de estudio de tatuagem, orcamento, agenda, tattoo, desenho, cliente."
    )

    for selected_engine in engines:
        if selected_engine == "openai":
            try:
                import whisper

                log_debug("loading openai-whisper model")
                model = whisper.load_model(model_name)
                log_debug("transcribing with openai-whisper")
                result = model.transcribe(
                    audio_path,
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
                log_debug(f"success openai-whisper chars={len(text)}")
                if text:
                    emit_json({"ok": True, "text": text, "engine": "openai"})
                    return 0
                log_debug("openai-whisper returned empty text")
            except ModuleNotFoundError:
                log_debug("openai-whisper not installed")
            except Exception as exc:
                log_debug("openai-whisper error: " + repr(exc))
                log_debug(traceback.format_exc())
                if engine == "openai":
                    emit_json({"ok": False, "error": f"openai-whisper: {exc}"})
                    return 1

        if selected_engine == "faster":
            try:
                from faster_whisper import WhisperModel

                log_debug("loading faster-whisper model")
                model = WhisperModel(model_name, device="cpu", compute_type="int8")
                log_debug("transcribing with faster-whisper")
                segments, _ = model.transcribe(
                    audio_path,
                    language="pt",
                    beam_size=5,
                    vad_filter=False,
                    condition_on_previous_text=False,
                    initial_prompt=prompt,
                )
                text = " ".join(segment.text.strip() for segment in segments).strip()
                log_debug(f"success faster-whisper chars={len(text)}")
                if text:
                    emit_json({"ok": True, "text": text, "engine": "faster"})
                    return 0
                log_debug("faster-whisper returned empty text")
            except ModuleNotFoundError:
                log_debug("faster-whisper not installed")
            except Exception as exc:
                log_debug("faster-whisper error: " + repr(exc))
                log_debug(traceback.format_exc())
                if engine == "faster":
                    emit_json({"ok": False, "error": f"faster-whisper: {exc}"})
                    return 1

    emit_json({
        "ok": False,
        "error": "Nao foi possivel reconhecer fala nesse audio"
    })
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
