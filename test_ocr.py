import os
os.environ["HF_HOME"] = "D:/hf_cache"  # Change "D:/hf_cache" to any folder on a drive with at least 5GB free space
import torch
from PIL import Image
from transformers import TrOCRProcessor, VisionEncoderDecoderModel

print("Loading French HTR Model...")
# Load the processor and the French fine-tuned model
processor = TrOCRProcessor.from_pretrained("microsoft/trocr-large-handwritten")
model = VisionEncoderDecoderModel.from_pretrained("agomberto/trocr-large-handwritten-fr")

# Load your cropped image snippet
image_path = "test_cell.jpg"  # Change to your cropped image filename
image = Image.open(image_path).convert("RGB")

print("Processing image...")
# Preprocess the image and generate text tokens
pixel_values = processor(images=image, return_tensors="pt").pixel_values
generated_ids = model.generate(pixel_values)

# Decode the model's output into a string
generated_text = processor.batch_decode(generated_ids, skip_special_tokens=True)[0]

print("\n--- RESULTS ---")
print(f"Predicted Text/Numbers: {generated_text}")
print("----------------")