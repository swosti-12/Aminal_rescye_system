"""
End-to-end pipeline: preprocess → YOLO animal gate → OpenCV injury cues.
Includes audit upload persistence and processing time metrics.
"""
from __future__ import annotations

import logging
import time
import uuid
from pathlib import Path
from typing import Any

from utils.animal_detector import detect_animals
from utils.config import (
    AUDIT_UPLOAD_DIR,
    GAUSSIAN_KERNEL,
    INCLUDE_PROCESSING_TIME,
    PERSIST_ANALYSIS_UPLOADS,
    PIPELINE_IMAGE_SIZE,
)
from utils.injury_detector import detect_injury_opencv, injury_severity_label
from utils.preprocessing import apply_clahe, prepare_bgr_for_inference
from utils.visualization import draw_full_annotation, save_annotated_image

logger = logging.getLogger(__name__)


def _compute_status(animal_detected: bool, injury_detected: bool) -> str:
    if not animal_detected:
        return "Rejected"
    if injury_detected:
        return "Needs Review"
    return "Accepted"


def _legacy_injury_confidence(injury_detected: bool, injury_score: float) -> float:
    """
    PHP / frontend expect a percentage-like confidence 0-100 for injury line.
    When injured: scale score upward so clear cases exceed 0.7 for backend acceptance hooks.
    """
    if injury_detected:
        return round(float(min(0.99, max(0.65, 0.55 + injury_score * 0.55))) * 100, 2)
    return round(float((1.0 - injury_score) * 35.0 + 50.0), 2)


def _persist_upload(image_bytes: bytes, ext: str = "jpg") -> str | None:
    """
    Save raw uploaded image to audit directory for traceability.
    Returns the relative path or None on failure.
    """
    try:
        audit_dir = Path(AUDIT_UPLOAD_DIR)
        audit_dir.mkdir(parents=True, exist_ok=True)
        filename = f"audit_{uuid.uuid4().hex[:12]}.{ext}"
        filepath = audit_dir / filename
        filepath.write_bytes(image_bytes)
        rel_path = str(filepath).replace("\\", "/")
        logger.info("Persisted audit upload: %s", rel_path)
        return rel_path
    except Exception as e:
        logger.error("Failed to persist audit upload: %s", e)
        return None


def run_pipeline(
    image_bytes: bytes,
    save_visualization: bool = False,
) -> dict[str, Any]:
    """
    Full analysis pipeline.

    Steps:
        1. Decode and preprocess image (resize 640x640, Gaussian blur)
        2. YOLO animal detection (filter to rescue-relevant species)
        3. If animal found → multi-channel injury detection (red + dark + texture)
        4. Generate status: Accepted / Rejected / Needs Review
        5. Optionally save annotated image with bounding boxes + injury overlay
        6. Persist upload for audit trail

    Returns JSON-serializable dict including legacy keys for analyze.php
    and structured fields for new clients.
    """
    t_start = time.perf_counter()

    # --- Step 1: Preprocess ---
    resized, blurred = prepare_bgr_for_inference(
        image_bytes, PIPELINE_IMAGE_SIZE, GAUSSIAN_KERNEL
    )

    # Apply CLAHE for better injury feature extraction
    enhanced = apply_clahe(blurred)

    # --- Step 2: Animal detection ---
    animal = detect_animals(resized)
    animal_detected = bool(animal["animal_detected"])
    best_conf = float(animal["best_confidence"])

    # --- Step 3: Injury detection (only if animal found) ---
    injury: dict[str, Any] = {
        "injury_detected": False,
        "injury_score": 0.0,
        "red_area_ratio": 0.0,
        "dark_area_ratio": 0.0,
        "contour_count": 0,
        "texture_score": 0.0,
        "mask_area_ratio": 0.0,
        "injury_contours": [],
        "score_breakdown": {},
    }

    if animal_detected:
        injury = detect_injury_opencv(enhanced)
    else:
        injury = {
            "injury_detected": False,
            "injury_score": 0.0,
            "red_area_ratio": 0.0,
            "dark_area_ratio": 0.0,
            "contour_count": 0,
            "texture_score": 0.0,
            "mask_area_ratio": 0.0,
            "injury_contours": [],
            "score_breakdown": {},
        }

    injury_detected = bool(injury.get("injury_detected", False))
    injury_score = float(injury.get("injury_score", 0.0))
    status = _compute_status(animal_detected, injury_detected)
    severity = injury_severity_label(injury_score)

    # --- Step 4: Visualization ---
    annotated_path: str | None = None
    if save_visualization and animal.get("detections"):
        vis = draw_full_annotation(
            resized,
            animal["detections"],
            injury.get("injury_contours", []),
            injury_detected,
            injury_score,
            severity,
        )
        annotated_path = save_annotated_image(vis, prefix="det")

    # --- Step 5: Audit persistence ---
    audit_path: str | None = None
    if PERSIST_ANALYSIS_UPLOADS:
        audit_path = _persist_upload(image_bytes)

    legacy_injury_conf = _legacy_injury_confidence(injury_detected, injury_score)

    # Combined confidence for summary: animal confidence when present, else 0
    confidence_score = round(best_conf if animal_detected else 0.0, 4)

    t_end = time.perf_counter()
    processing_time_ms = round((t_end - t_start) * 1000, 1)

    out: dict[str, Any] = {
        # --- New structured fields ---
        "animal_detected": animal_detected,
        "injury_detected": injury_detected if animal_detected else False,
        "confidence_score": confidence_score,
        "status": status,
        "injury_score": round(injury_score, 4),
        "animal_label": animal.get("best_label", "none"),
        "detections": animal.get("detections", []),
        "injury_features": {
            "red_area_ratio": injury.get("red_area_ratio", 0.0),
            "dark_area_ratio": injury.get("dark_area_ratio", 0.0),
            "contour_count": injury.get("contour_count", 0),
            "texture_score": injury.get("texture_score", 0.0),
            "score_breakdown": injury.get("score_breakdown", {}),
        },
        # --- Legacy analyze.php / demos ---
        "animal_type": animal.get("best_label", "none"),
        "confidence": round(best_conf * 100, 2) if animal_detected else 0.0,
        "injury_severity": severity if animal_detected else "none",
        "injury_confidence": legacy_injury_conf,
        # --- Optional artifacts ---
        "annotated_image_path": annotated_path,
        "audit_image_path": audit_path,
    }

    # Add processing time if enabled
    if INCLUDE_PROCESSING_TIME:
        out["processing_time_ms"] = processing_time_ms

    logger.info(
        "Pipeline complete: %s conf=%.2f injury=%s status=%s [%.0fms]",
        animal.get("best_label", "none"),
        best_conf * 100,
        severity,
        status,
        processing_time_ms,
    )

    return out
