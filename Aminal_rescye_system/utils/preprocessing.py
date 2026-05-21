"""
Image preprocessing: resize, noise reduction, color space conversion, CLAHE.
"""
from __future__ import annotations

import cv2
import numpy as np


def bytes_to_bgr(image_bytes: bytes) -> np.ndarray | None:
    """Decode raw bytes to BGR image (OpenCV native)."""
    arr = np.frombuffer(image_bytes, dtype=np.uint8)
    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    return img


def preprocess_for_pipeline(
    bgr: np.ndarray,
    size: int,
    gaussian_kernel: tuple[int, int],
) -> tuple[np.ndarray, np.ndarray]:
    """
    Returns:
        resized_bgr: shape (size, size, 3) — used for YOLO and downstream CV
        blurred_bgr: same size, Gaussian blur applied — stabilizes injury segmentation
    """
    resized = cv2.resize(bgr, (size, size), interpolation=cv2.INTER_AREA)
    blurred = cv2.GaussianBlur(resized, gaussian_kernel, 0)
    return resized, blurred


def apply_clahe(bgr: np.ndarray, clip_limit: float = 2.0, tile_size: int = 8) -> np.ndarray:
    """
    Apply CLAHE (Contrast Limited Adaptive Histogram Equalization) to the
    luminance channel of a BGR image.  This improves feature visibility in
    dark or low-contrast injury regions without altering colour balance.

    Args:
        bgr: Input BGR image
        clip_limit: CLAHE contrast clip limit (higher = more contrast)
        tile_size: Grid size for histogram equalization

    Returns:
        BGR image with enhanced contrast
    """
    lab = cv2.cvtColor(bgr, cv2.COLOR_BGR2LAB)
    l_channel, a_channel, b_channel = cv2.split(lab)

    clahe = cv2.createCLAHE(
        clipLimit=clip_limit,
        tileGridSize=(tile_size, tile_size),
    )
    l_enhanced = clahe.apply(l_channel)

    lab_enhanced = cv2.merge([l_enhanced, a_channel, b_channel])
    return cv2.cvtColor(lab_enhanced, cv2.COLOR_LAB2BGR)


def bgr_to_hsv(bgr: np.ndarray) -> np.ndarray:
    return cv2.cvtColor(bgr, cv2.COLOR_BGR2HSV)


def prepare_bgr_for_inference(
    image_bytes: bytes,
    size: int,
    gaussian_kernel: tuple[int, int],
) -> tuple[np.ndarray, np.ndarray]:
    """
    Decode bytes and apply the same resize + blur as the main pipeline.
    Returns (resized_bgr, blurred_bgr).
    """
    bgr = bytes_to_bgr(image_bytes)
    if bgr is None:
        raise ValueError("Could not decode image")
    return preprocess_for_pipeline(bgr, size, gaussian_kernel)
