"""VeriAge : microservice biométrique local (MRZ, visage, contrôle du vivant).

Tout s'exécute sur le serveur : aucun service externe, aucun appel réseau à l'exécution. Les images
sont traitées en mémoire uniquement et ne sont jamais écrites sur disque ni journalisées.
"""
import os

# Garde-fou contre les « bombes de décompression » : OpenCV refuse de décoder une image plus grande
# que ce nombre de pixels. Doit être fixé AVANT le premier import de cv2.
os.environ.setdefault("OPENCV_IO_MAX_IMAGE_PIXELS", str(24_000_000))
# Tesseract (OpenMP) : un seul fil par appel, le parallélisme est géré par le service.
os.environ.setdefault("OMP_THREAD_LIMIT", "1")

__version__ = "1.0.0"
