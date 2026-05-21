"""
Animal detection using Ultralytics YOLOv8 (COCO pretrained).
Filters detections to species relevant to rescue workflows.
"""
from __future__ import annotations

import logging
from typing import Any

import numpy as np

from utils.config import (
    RELEVANT_ANIMAL_NAMES,
    YOLO_CONFIDENCE,
    YOLO_IOU,
    YOLO_MODEL_NAME,
)

logger = logging.getLogger(__name__)

_yolo_model = None


def get_yolo_model(): #loads yolo model on first use, and returns it. 
    """Lazy singleton load — avoids slow import at Flask startup."""
    global _yolo_model
    if _yolo_model is None:
        from ultralytics import YOLO

        # Ultralytics downloads yolov8n.pt on first use if not present
        path = YOLO_MODEL_NAME
        if not path.endswith(".pt"):
            path = f"{path}.pt"
        _yolo_model = YOLO(path)
        logger.info("YOLOv8 model loaded: %s", path)
    return _yolo_model


def detect_animals( 
    bgr_image: np.ndarray,
    conf: float | None = None,
) -> dict[str, Any]:
    """
    Run YOLO on a BGR image (any size; model letterboxes internally).

    Returns:
        animal_detected: bool — any relevant class above threshold
        best_label: str — highest-confidence relevant class, or "none"
        best_confidence: float — 0..1 for best box
        detections: list of {label, confidence, bbox: {x1,y1,x2,y2}} (pixel coords on input image)
    """
    conf = YOLO_CONFIDENCE if conf is None else conf
    model = get_yolo_model()
    h, w = bgr_image.shape[:2]

    results = model.predict(  #CNN is triggered here, and is the slow part of this function
        source=bgr_image,
        conf=conf,
        iou=YOLO_IOU,
        verbose=False,
    )
    if not results:
        return _empty_result()

    r0 = results[0]
    boxes = getattr(r0, "boxes", None)
    if boxes is None or len(boxes) == 0:
        return _empty_result()

    names = model.names  # class_id -> name
    relevant: list[dict[str, Any]] = []

    for b in boxes:
        cls_id = int(b.cls.item())
        label = names.get(cls_id, str(cls_id)).lower()
        if label not in RELEVANT_ANIMAL_NAMES:
            continue
        score = float(b.conf.item())
        xyxy = b.xyxy.cpu().numpy().flatten().tolist()
        x1, y1, x2, y2 = [float(v) for v in xyxy]
        relevant.append(
            {
                "label": label,
                "confidence": score,
                "bbox": {"x1": x1, "y1": y1, "x2": x2, "y2": y2},
            }
        )

    if not relevant:
        return {
            "animal_detected": False,
            "best_label": "none",
            "best_confidence": 0.0,
            "detections": [],
        }

    relevant.sort(key=lambda x: x["confidence"], reverse=True) #sorts the relevant detections by confidence, highest first
    best = relevant[0]
    return {
        "animal_detected": True,
        "best_label": best["label"],
        "best_confidence": best["confidence"],
        "detections": relevant,
    }


def _empty_result() -> dict[str, Any]:
    return {
        "animal_detected": False,
        "best_label": "none",
        "best_confidence": 0.0,
        "detections": [],
    }
