import { Component, inject, ChangeDetectorRef } from '@angular/core'; 
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http'; 

export type Shift = 'Matin' | 'Soir' | 'Nuit';

export interface WagonEntry {
  line: number;
  wagonNumber: string;
  produitCode: string;
  packedSymbol: string;
  bulkSymbol: string;
  packedQuantity: number | null;
  bulkQuantity: number | null;
  stopReason: string;
  stopDuration: string;
}

export interface ProductionReport {
  productionDate: string;
  productionUnit: string;
  shift: Shift;
  departEmb: number | null;
  resteEmb: number | null;
  deuxiemeEmb: number | null;
  stockWagonsInitial: number | null;
  stockWagonsFinal: number | null;
  initialPackets: number | null;
  finalPackets: number | null;
  notes: string;
  operators?: string[];
  wagons: WagonEntry[];
}

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './app.html',
  styleUrls: ['./app.css']
})
export class AppComponent {
  title = 'Factory Verification Center';
  fileUploaded = false;
  isAnalyzing = false;
  
  private http = inject(HttpClient);
  private cdr = inject(ChangeDetectorRef); // Injected to force Angular to update the UI immediately

  reportData: ProductionReport = {
    productionDate: '',
    productionUnit: '',
    shift: 'Matin',
    departEmb: null,
    resteEmb: null,
    deuxiemeEmb: null,
    stockWagonsInitial: null,
    stockWagonsFinal: null,
    initialPackets: null,
    finalPackets: null,
    notes: '',
    wagons: []
  };

  onFileSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    if (!input.files || input.files.length === 0) return;

    const file = input.files[0];
    this.uploadPdfToLaravel(file);
  }

  uploadPdfToLaravel(file: File) {
    this.isAnalyzing = true;
    this.fileUploaded = false;
    this.cdr.detectChanges(); // Force UI to show the loading spinner

    const formData = new FormData();
    formData.append('pdf', file);

    this.http.post<any>('http://127.0.0.1:8000/api/ingest-pdf', formData).subscribe({
      next: (response) => {
        if (response && response.success && response.data) {
          this.reportData = {
            productionDate: response.data.productionDate || '',
            productionUnit: response.data.productionUnit || '',
            shift: response.data.shift || 'Matin',
            departEmb: response.data.departEmb ?? null,
            resteEmb: response.data.resteEmb ?? null,
            deuxiemeEmb: response.data.deuxiemeEmb ?? null,
            stockWagonsInitial: response.data.stockWagonsInitial ?? null,
            stockWagonsFinal: response.data.stockWagonsFinal ?? null,
            initialPackets: response.data.initialPackets ?? null,
            finalPackets: response.data.finalPackets ?? null,
            notes: response.data.notes || '',
            wagons: Array.isArray(response.data.wagons) 
              ? response.data.wagons.map((w: any) => ({
                  line: w.line ?? null,
                  wagonNumber: w.wagonNumber || '',
                  produitCode: w.produitCode || '',
                  packedSymbol: w.packedSymbol || '',
                  packedQuantity: w.packedQuantity ?? null,
                  bulkSymbol: w.bulkSymbol || '',
                  bulkQuantity: w.bulkQuantity ?? null,
                  stopReason: w.stopReason || '',
                  stopDuration: w.stopDuration || ''
                }))
              : []
          };

          this.fileUploaded = true;
          console.log('Form data successfully synchronized:', this.reportData);
        } else {
          alert('Failed to parse document details.');
        }
        this.isAnalyzing = false;
        this.cdr.detectChanges(); // Force Angular to render the newly arrived data and hide spinner
      },
      error: (err) => {
        console.error('API Error:', err);
        alert('Could not connect to backend server. Make sure Laravel is running (php artisan serve).');
        this.isAnalyzing = false;
        this.cdr.detectChanges(); // Force UI update even on error
      }
    });
  }

  submitVerifiedData() {
    console.log('Sending v2.0 Data Package to Laravel:', this.reportData);
    alert('Data successfully exported! Ready for Laravel Event Generation.');
  }

  trackByLine(index: number, item: WagonEntry) {
    return item.line;
  }
}