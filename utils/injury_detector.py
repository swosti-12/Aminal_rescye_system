"""
Simplified injury cue detection using OpenCV (no deep learning).

Approach (explainable for academic / viva):
1. HSV color segmentation for red / red-like tones (blood, inflammation, severe wounds).
2. Dark wound / bruise detection via low-Value HSV regions (deep wounds, necrotic tissue).
3. Texture analysis using Laplacian variance on candidate regions.
4. Morphological cleanup to reduce noise.
5. Contour analysis: count and area of abnormal regions vs. image area.
6. Weighted scoring: red + dark + texture → final injury_score in [0, 1].
"""
from __future__ import annotations

import logging

import cv2
import numpy as np

from utils.config import (
    DARK_WOUND_H_RANGE,
    DARK_WOUND_MIN_CONTOUR_AREA,
    DARK_WOUND_S_MIN,
    DARK_WOUND_V_MAX,
    ENABLE_DARK_WOUND_DETECTION,
    INJURY_MIN_CONTOUR_AREA,
    INJURY_SCORE_THRESHOLD,
    INJURY_WEIGHT_DARK,
    INJURY_WEIGHT_RED,
    INJURY_WEIGHT_TEXTURE,
    TEXTURE_LAPLACIAN_THRESHOLD,
)

logger = logging.getLogger(__name__)


# ---------------------------------------------------------------
# HSV masks
# ---------------------------------------------------------------

def _red_masks_hsv(hsv: np.ndarray) -> np.ndarray:
    """
    Red appears at both ends of the Hue axis in OpenCV HSV (H: 0-180).
    Combine two ranges + a broad "orange-red" band for saliency.
    """
    # Lower red wedge  (H: 0-10)
    lower1 = np.array([0, 40, 40])
    upper1 = np.array([10, 255, 255])
    mask1 = cv2.inRange(hsv, lower1, upper1)

    # Upper red wedge  (H: 160-180)
    lower2 = np.array([160, 40, 40])
    upper2 = np.array([180, 255, 255])
    mask2 = cv2.inRange(hsv, lower2, upper2)

    mask = cv2.bitwise_or(mask1, mask2)
    return mask


def _dark_wound_mask_hsv(hsv: np.ndarray) -> np.ndarray:
    """
    Detect dark wound / bruise regions: areas with very low brightness (Value)
    that are NOT pure black background.  This captures:
      - Deep open wounds (dark red / brown tissue)
      - Bruising (purple / dark blue)
      - Necrotic / scabbed tissue
    
    We require a minimum saturation to avoid matching cast shadows and
    black fur / feathers (which have near-zero saturation).
    """
    h_lo, h_hi = DARK_WOUND_H_RANGE
    lower = np.array([h_lo, DARK_WOUND_S_MIN, 10])   # V > 10 to skip pure black
    upper = np.array([h_hi, 255, DARK_WOUND_V_MAX])
    mask = cv2.inRange(hsv, lower, upper)
    return mask


# ---------------------------------------------------------------
# Texture analysis
# ---------------------------------------------------------------

def _compute_texture_score(
    gray: np.ndarray,
    mask: np.ndarray,
) -> float:
    """
    Compute Laplacian variance within masked regions to detect wound textures.
    Wounds tend to have rough/irregular texture → higher Laplacian variance
    compared to smooth fur / skin.

    Returns a normalised score in [0, 1].
    """
    if cv2.countNonZero(mask) < 50:
        return 0.0

    # Apply mask to grayscale image
    masked_gray = cv2.bitwise_and(gray, gray, mask=mask)
    laplacian = cv2.Laplacian(masked_gray, cv2.CV_64F)

    # Compute variance only within mask region
    lap_values = laplacian[mask > 0]
    if len(lap_values) == 0:
        return 0.0

    variance = float(np.var(lap_values))
    # Normalise: threshold acts as a soft cap
    score = min(1.0, variance / max(TEXTURE_LAPLACIAN_THRESHOLD, 1.0))
    return score


# ---------------------------------------------------------------
# Contour helpers
# ---------------------------------------------------------------

def _analyze_contours(
    mask: np.ndarray,
    min_area: int,
) -> tuple[int, float, list]:
    """
    Find significant contours in a binary mask.

    Returns:
        count: number of significant contours
        total_area_ratio: fraction of image covered by significant contours
        contours: list of significant contour arrays (for visualization)
    """
    total_px = mask.shape[0] * mask.shape[1]
    contours, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    sig = [c for c in contours if cv2.contourArea(c) >= min_area]
    count = len(sig)
    area = sum(cv2.contourArea(c) for c in sig)
    area_ratio = area / max(total_px, 1)
    return count, area_ratio, sig


# ---------------------------------------------------------------
# Main injury detection
# ---------------------------------------------------------------

def detect_injury_opencv(
    blurred_bgr: np.ndarray,
    score_threshold: float | None = None,
) -> dict:
    """
    Multi-channel injury detection using OpenCV heuristics.

    Pipeline:
        1. Convert to HSV
        2. Red mask → morphological cleanup → contour analysis
        3. Dark wound mask → morphological cleanup → contour analysis
        4. Texture analysis (Laplacian variance) on combined injury mask
        5. Weighted scoring: red + dark + texture
        6. Threshold → injury_detected boolean

    Args:
        blurred_bgr: 640x640 BGR image after Gaussian blur

    Returns:
        injury_detected: bool
        injury_score: float in [0, 1]
        red_area_ratio: float — fraction of pixels in red mask
        dark_area_ratio: float — fraction of pixels in dark wound mask
        contour_count: int — total significant contours (red + dark)
        texture_score: float — Laplacian variance normalised score
        mask_area_ratio: float — combined mask coverage (legacy compat)
        injury_contours: list — contour arrays for visualization
    """
    thr = INJURY_SCORE_THRESHOLD if score_threshold is None else score_threshold

    # --- Step 1: Colour space conversions ---
    hsv = cv2.cvtColor(blurred_bgr, cv2.COLOR_BGR2HSV)
    gray = cv2.cvtColor(blurred_bgr, cv2.COLOR_BGR2GRAY)

    # Morphological kernel (shared)
    kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (5, 5))

    # --- Step 2: Red blood / inflammation mask ---
    red_mask = _red_masks_hsv(hsv)
    red_mask = cv2.morphologyEx(red_mask, cv2.MORPH_CLOSE, kernel, iterations=2)
    red_mask = cv2.morphologyEx(red_mask, cv2.MORPH_OPEN, kernel, iterations=1)

    total_px = blurred_bgr.shape[0] * blurred_bgr.shape[1]
    red_area = float(cv2.countNonZero(red_mask))
    red_area_ratio = red_area / max(total_px, 1)

    red_contour_count, red_contour_ratio, red_contours = _analyze_contours(
        red_mask, INJURY_MIN_CONTOUR_AREA
    )

    # Red component score (same formula as original for backwards compat)
    red_area_component = min(1.0, red_area_ratio * 18.0)
    red_contour_component = min(1.0, red_contour_count / 8.0)
    red_score = 0.62 * red_area_component + 0.38 * red_contour_component

    # --- Step 3: Dark wound / bruise mask ---
    dark_area_ratio = 0.0
    dark_contour_count = 0
    dark_score = 0.0
    dark_contours = []

    if ENABLE_DARK_WOUND_DETECTION:
        dark_mask = _dark_wound_mask_hsv(hsv)
        dark_mask = cv2.morphologyEx(dark_mask, cv2.MORPH_CLOSE, kernel, iterations=2)
        dark_mask = cv2.morphologyEx(dark_mask, cv2.MORPH_OPEN, kernel, iterations=1)

        dark_area = float(cv2.countNonZero(dark_mask))
        dark_area_ratio = dark_area / max(total_px, 1)

        dark_contour_count, dark_contour_ratio, dark_contours = _analyze_contours(
            dark_mask, DARK_WOUND_MIN_CONTOUR_AREA
        )

        dark_area_component = min(1.0, dark_area_ratio * 14.0)
        dark_contour_component = min(1.0, dark_contour_count / 6.0)
        dark_score = 0.55 * dark_area_component + 0.45 * dark_contour_component

    # --- Step 4: Texture analysis on combined injury region ---
    combined_mask = red_mask.copy()
    if ENABLE_DARK_WOUND_DETECTION:
        combined_mask = cv2.bitwise_or(combined_mask, dark_mask)

    texture_score = _compute_texture_score(gray, combined_mask)

    # --- Step 5: Weighted final score ---
    if ENABLE_DARK_WOUND_DETECTION:
        injury_score = (
            INJURY_WEIGHT_RED * red_score
            + INJURY_WEIGHT_DARK * dark_score
            + INJURY_WEIGHT_TEXTURE * texture_score
        )
    else:
        # Fallback: original two-component formula if dark detection disabled
        injury_score = 0.62 * red_area_component + 0.38 * red_contour_component

    injury_score = float(max(0.0, min(1.0, injury_score)))

    # --- Step 6: Threshold decision ---
    injury_detected = injury_score >= thr

    # Total contour count for API response
    total_contour_count = red_contour_count + dark_contour_count
    combined_area_ratio = red_area_ratio + dark_area_ratio

    # Collect all contours for visualization (red + dark)
    all_injury_contours = red_contours + dark_contours

    logger.debug(
        "Injury analysis: red=%.3f dark=%.3f texture=%.3f → score=%.3f detected=%s",
        red_score, dark_score, texture_score, injury_score, injury_detected,
    )

    return {
        "injury_detected": injury_detected,
        "injury_score": injury_score,
        "red_area_ratio": round(red_area_ratio, 6),
        "dark_area_ratio": round(dark_area_ratio, 6),
        "contour_count": total_contour_count,
        "texture_score": round(texture_score, 4),
        "mask_area_ratio": round(combined_area_ratio, 6),
        "injury_contours": all_injury_contours,
        # Component breakdown (useful for viva / debugging)
        "score_breakdown": {
            "red_score": round(red_score, 4),
            "dark_score": round(dark_score, 4),
            "texture_score": round(texture_score, 4),
            "weights": {
                "red": INJURY_WEIGHT_RED,
                "dark": INJURY_WEIGHT_DARK,
                "texture": INJURY_WEIGHT_TEXTURE,
            },
        },
    }


def injury_confidence_for_api(injury_detected: bool, injury_score: float) -> float:
    """
    Map heuristic score to a 0-1 confidence value for PHP (AiImageAnalysisService).
    Injured cases should often exceed 0.7 when injury_score is above threshold.
    """
    if injury_detected:
        return round(float(min(0.98, max(0.71, 0.58 + injury_score * 0.42))), 4)
    return round(float(min(0.92, max(0.51, 0.82 - injury_score * 0.35))), 4)


def injury_severity_label(injury_score: float) -> str:
    """Map continuous score to discrete labels for legacy API fields."""
    if injury_score < 0.12:
        return "none"
    if injury_score < 0.28:
        return "minor"
    if injury_score < 0.45:
        return "moderate"
    return "severe"
