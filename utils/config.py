"""
Central configuration for the image analysis pipeline.
Override via environment variables where noted.
"""
import os

# --- Paths (relative to project root when Flask runs from there) ---
UPLOAD_DIR = os.environ.get("PAWALERT_UPLOAD_DIR", "uploads")
OUTPUT_DIR = os.environ.get("PAWALERT_OUTPUT_DIR", "outputs")
MODELS_DIR = os.environ.get("PAWALERT_MODELS_DIR", "models")

# Sub-directory inside UPLOAD_DIR for persisted analysis uploads
AUDIT_UPLOAD_DIR = os.environ.get(
    "PAWALERT_AUDIT_DIR", os.path.join(UPLOAD_DIR, "analysis_audit")
)
# Persist every image sent to /analyze for audit trail
PERSIST_ANALYSIS_UPLOADS = os.environ.get(
    "PAWALERT_PERSIST_UPLOADS", "1"
) in ("1", "true", "yes")

# YOLO: first run downloads weights; can point to a local .pt file
YOLO_MODEL_NAME = os.environ.get("PAWALERT_YOLO_MODEL", "yolov8n.pt")

# Only COCO classes relevant to animal rescue (names must match Ultralytics COCO labels)
RELEVANT_ANIMAL_NAMES = frozenset(
    {"bird", "cat", "dog", "horse", "sheep", "cow"}
)

# Inference size (square)
PIPELINE_IMAGE_SIZE = int(os.environ.get("PAWALERT_IMAGE_SIZE", "640"))

# Animal: minimum confidence to count a detection (0–1)
# Lower floor = keep marginal boxes (lying animals, blood/contrast, partial occlusion)
YOLO_CONFIDENCE = float(os.environ.get("PAWALERT_YOLO_CONFIDENCE", "0.20"))
YOLO_IOU = float(os.environ.get("PAWALERT_YOLO_IOU", "0.45"))

# Injury (HSV / contour heuristic): score above this => injury_detected True
INJURY_SCORE_THRESHOLD = float(os.environ.get("PAWALERT_INJURY_THRESHOLD", "0.22"))

# Minimum contour area (pixels) on 640x640 to ignore noise
INJURY_MIN_CONTOUR_AREA = int(os.environ.get("PAWALERT_MIN_CONTOUR_AREA", "120"))

# Gaussian blur kernel (must be odd)
GAUSSIAN_KERNEL = (5, 5)

# ---------------------------------------------------------------
# Dark wound / bruise detection (new — extends injury heuristic)
# ---------------------------------------------------------------
# Enable multi-channel injury analysis (red + dark wounds)
ENABLE_DARK_WOUND_DETECTION = os.environ.get(
    "PAWALERT_DARK_WOUND", "1"
) in ("1", "true", "yes")

# HSV range for dark wound / bruise regions (low Value = dark area)
DARK_WOUND_H_RANGE = (0, 180)        # full hue — darkness is hue-agnostic
DARK_WOUND_S_MIN = int(os.environ.get("PAWALERT_DARK_S_MIN", "15"))
DARK_WOUND_V_MAX = int(os.environ.get("PAWALERT_DARK_V_MAX", "65"))

# Minimum contour area for dark wound blobs (larger to avoid shadows)
DARK_WOUND_MIN_CONTOUR_AREA = int(
    os.environ.get("PAWALERT_DARK_MIN_AREA", "200")
)

# Weight balance: [red_weight, dark_weight, texture_weight]
# These control how much each signal contributes to final injury_score
INJURY_WEIGHT_RED = float(os.environ.get("PAWALERT_W_RED", "0.45"))
INJURY_WEIGHT_DARK = float(os.environ.get("PAWALERT_W_DARK", "0.30"))
INJURY_WEIGHT_TEXTURE = float(os.environ.get("PAWALERT_W_TEXTURE", "0.25"))

# Texture analysis: Laplacian variance threshold for wound texture
TEXTURE_LAPLACIAN_THRESHOLD = float(
    os.environ.get("PAWALERT_TEXTURE_THRESHOLD", "350.0")
)

# Include processing time in API responses
INCLUDE_PROCESSING_TIME = os.environ.get(
    "PAWALERT_INCLUDE_TIMING", "1"
) in ("1", "true", "yes")
