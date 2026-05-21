"""
Draw YOLO boxes and injury overlays on images for dashboard / debugging.
"""
from __future__ import annotations

import uuid
import logging
from pathlib import Path

import cv2
import numpy as np

from utils.config import OUTPUT_DIR

logger = logging.getLogger(__name__)


def draw_detections(
    bgr: np.ndarray,
    detections: list[dict],
) -> np.ndarray:
    """Returns a copy with bounding-box rectangles and labels."""
    out = bgr.copy()
    for det in detections:
        bb = det.get("bbox") or {}
        x1, y1 = int(bb.get("x1", 0)), int(bb.get("y1", 0))
        x2, y2 = int(bb.get("x2", 0)), int(bb.get("y2", 0))
        label = det.get("label", "?")
        conf = float(det.get("confidence", 0))
        cv2.rectangle(out, (x1, y1), (x2, y2), (0, 200, 255), 2)
        text = f"{label} {conf:.2f}"
        cv2.putText(
            out,
            text,
            (x1, max(15, y1 - 6)),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.5,
            (0, 200, 255),
            1,
            cv2.LINE_AA,
        )
    return out


def draw_injury_overlay(
    bgr: np.ndarray,
    injury_contours: list,
    injury_detected: bool,
    injury_score: float,
    severity: str = "none",
    alpha: float = 0.35,
) -> np.ndarray:
    """
    Draw semi-transparent injury overlay highlighting detected wound regions.

    Args:
        bgr: Input BGR image (will be copied)
        injury_contours: List of contour arrays from injury detection
        injury_detected: Whether injury was detected
        injury_score: Injury severity score (0-1)
        severity: Severity label string
        alpha: Transparency of the overlay (0 = invisible, 1 = opaque)

    Returns:
        BGR image with injury regions highlighted and severity badge
    """
    out = bgr.copy()

    if injury_contours and injury_detected:
        overlay = out.copy()

        # Draw filled contours on overlay (red tint for injury regions)
        cv2.drawContours(overlay, injury_contours, -1, (0, 0, 220), thickness=cv2.FILLED)

        # Blend overlay with original
        cv2.addWeighted(overlay, alpha, out, 1 - alpha, 0, out)

        # Draw contour outlines for clarity
        cv2.drawContours(out, injury_contours, -1, (0, 0, 255), 1)

    # Draw severity badge in top-right corner
    _draw_severity_badge(out, severity, injury_score, injury_detected)

    return out


def _draw_severity_badge(
    bgr: np.ndarray,
    severity: str,
    score: float,
    detected: bool,
) -> None:
    """Draw a coloured severity badge in the top-right corner."""
    h, w = bgr.shape[:2]

    # Badge colours by severity
    colours = {
        "none": (80, 180, 80),       # green
        "minor": (0, 200, 255),      # yellow-orange
        "moderate": (0, 128, 255),   # orange
        "severe": (0, 0, 220),       # red
    }
    bg_colour = colours.get(severity, (128, 128, 128))
    text_colour = (255, 255, 255)

    label = f"Injury: {severity.upper()} ({score:.0%})"
    font = cv2.FONT_HERSHEY_SIMPLEX
    font_scale = 0.5
    thickness = 1

    (tw, th), baseline = cv2.getTextSize(label, font, font_scale, thickness)
    pad = 6

    # Position: top-right corner
    x1 = w - tw - pad * 3
    y1 = pad
    x2 = w - pad
    y2 = th + pad * 3

    cv2.rectangle(bgr, (x1, y1), (x2, y2), bg_colour, cv2.FILLED)
    cv2.rectangle(bgr, (x1, y1), (x2, y2), (255, 255, 255), 1)

    text_x = x1 + pad
    text_y = y1 + th + pad
    cv2.putText(bgr, label, (text_x, text_y), font, font_scale, text_colour, thickness, cv2.LINE_AA)


def draw_full_annotation(
    bgr: np.ndarray,
    detections: list[dict],
    injury_contours: list,
    injury_detected: bool,
    injury_score: float,
    severity: str = "none",
) -> np.ndarray:
    """
    Combine animal bounding boxes and injury overlay into a single annotated image.
    This is the main visualization function for the pipeline.
    """
    # First draw animal detections (bounding boxes)
    annotated = draw_detections(bgr, detections)
    # Then overlay injury regions
    annotated = draw_injury_overlay(
        annotated, injury_contours, injury_detected, injury_score, severity
    )
    return annotated


def save_annotated_image(bgr: np.ndarray, prefix: str = "annotated") -> str | None:
    """
    Save BGR image under outputs/. Returns relative web-friendly path or None on failure.
    """
    try:
        Path(OUTPUT_DIR).mkdir(parents=True, exist_ok=True)
        name = f"{prefix}_{uuid.uuid4().hex[:12]}.jpg"
        path = Path(OUTPUT_DIR) / name
        cv2.imwrite(str(path), bgr)
        logger.info("Saved annotated image: %s", path)
        return str(path).replace("\\", "/")
    except Exception as e:
        logger.error("Failed to save annotated image: %s", e)
        return None
