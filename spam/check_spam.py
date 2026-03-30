import os
import sys
from typing import Any

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel

MIN_PYTHON = (3, 10)
MAX_PYTHON_EXCLUSIVE = (3, 14)

# En Windows, forzamos UTF-8 para que Keras pueda leer correctamente
# assets de TextVectorization serializados en UTF-8.
if os.name == "nt" and not sys.flags.utf8_mode:
    os.execv(sys.executable, [sys.executable, "-X", "utf8", *sys.argv])

if not (MIN_PYTHON <= sys.version_info < MAX_PYTHON_EXCLUSIVE):
    current = f"{sys.version_info.major}.{sys.version_info.minor}"
    raise RuntimeError(
        "Version de Python no compatible con TensorFlow en este proyecto. "
        f"Actual: {current}. Usa Python 3.10-3.13."
    )

try:
    import tensorflow as tf
except ImportError as exc:
    raise RuntimeError(
        "TensorFlow no esta instalado en este entorno. "
        "Crea/activa un venv con Python 3.13 y ejecuta: pip install -r requirements_api.txt"
    ) from exc


MODEL_PATH = os.path.join(os.path.dirname(__file__), "modelo_spam_completo.keras")
MODEL_NAME = "modelo_spam_completo.keras"
THRESHOLD = 0.5

if not os.path.isfile(MODEL_PATH):
    raise RuntimeError(f"No se encontro el modelo en: {MODEL_PATH}")


def _load_model() -> Any:
    # El modelo se carga una sola vez al iniciar el servicio.
    return tf.keras.models.load_model(MODEL_PATH)


model = _load_model()


class PredictRequest(BaseModel):
    text: str


class PredictResponse(BaseModel):
    is_spam: bool
    spam_probability: float
    threshold: float
    model: str


app = FastAPI(title="AnonChat Spam API", version="1.0.0")


def predict_spam_score(text: str) -> float:
    if not text:
        return 0.0

    # Keras 3 es estricto con el tipo de entrada; usamos tensor string explícito.
    inputs = tf.constant([text], dtype=tf.string)
    preds = model.predict(inputs, verbose=0)
    try:
        score = float(preds[0][0])
    except Exception:
        try:
            score = float(preds[0])
        except Exception:
            score = float(preds)

    if score < 0:
        score = 0.0
    if score > 1:
        score = 1.0
    return score


@app.get("/health")
def health() -> dict[str, Any]:
    return {"ok": True, "model": MODEL_NAME}


@app.post("/predict", response_model=PredictResponse)
def predict(payload: PredictRequest) -> PredictResponse:
    text = payload.text.strip()
    if text == "":
        return PredictResponse(
            is_spam=False,
            spam_probability=0.0,
            threshold=THRESHOLD,
            model=MODEL_NAME,
        )

    try:
        score = predict_spam_score(text)
    except Exception as exc:
        raise HTTPException(status_code=500, detail=f"Error en prediccion: {exc}") from exc

    return PredictResponse(
        is_spam=(score >= THRESHOLD),
        spam_probability=round(score, 6),
        threshold=THRESHOLD,
        model=MODEL_NAME,
    )


if __name__ == "__main__":
    import uvicorn

    uvicorn.run("check_spam:app", host="127.0.0.1", port=8000, reload=False)
