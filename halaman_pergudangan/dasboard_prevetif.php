<?php
include "../inc/config.php";
requireAuth('pergudangan');
include "navbar.php";
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Preventif Perawatan Armada</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', sans-serif;
            background:rgb(185, 224, 204);
                }

        /* Top Header */
        .top-header {
            background: white;
            padding: 15px 25px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 70px;
        }

        .top-header h1 {
            font-size: 20px;
            font-weight: 700;
            color: #1e293b;
        }

        .header-actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .filter-compact {
            display: flex;
            gap: 10px;
        }

        .filter-compact select {
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 13px;
            background: white;
        }

        /* Dashboard Content */
        .dashboard-content {
            padding: 20px;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 15px;
        }

        /* Stats Cards Row */
        .stats-row {
            grid-column: span 12;
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 12px;
            margin-bottom: 15px;
        }

        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            border-top: 3px solid var(--color);
        }

        .stat-card h3 {
            font-size: 11px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .stat-card .value {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .stat-card .detail {
            font-size: 11px;
            color: #64748b;
        }

        /* Charts Section */
        .chart-box {
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            min-height: 300px;
        }

        .chart-box h2 {
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 10px;
        }

        .chart-box.col-3 { grid-column: span 3; }
        .chart-box.col-4 { grid-column: span 4; }
        .chart-box.col-6 { grid-column: span 6; }
        .chart-box.col-8 { grid-column: span 8; }

        .chart-container {
            position: relative;
            height: 250px;
        }

        /* Table Compact */
        .table-box {
            grid-column: span 12;
            background: white;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        .table-box h2 {
            font-size: 14px;
            font-weight: 600;
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
        }

        .table-scroll {
            overflow-y: auto;
            max-height: 400px;
        }

        .compact-table {
            width: 100%;
            border-collapse: collapse;
        }

        .compact-table thead {
            background: #f8fafc;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .compact-table th {
            padding: 10px 12px;
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            color: #475569;
            text-transform: uppercase;
            border-bottom: 1px solid #e2e8f0;
        }

        .compact-table td {
            padding: 10px 12px;
            font-size: 12px;
            border-bottom: 1px solid #f1f5f9;
        }

        .compact-table tbody tr:hover {
            background: #f8fafc;
        }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }

        .badge.blue { background: #dbeafe; color: #1e40af; }
        .badge.green { background: #d1fae5; color: #065f46; }
        .badge.orange { background: #fed7aa; color: #92400e; }
        .badge.red { background: #fee2e2; color: #991b1b; }
        .badge.purple { background: #f3e8ff; color: #6b21a8; }

        /* Alert Banner */
        .alert-banner {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 13px;
            color: #92400e;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Mobile Responsive */
        @media (max-width: 1400px) {
            .stats-row {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 1024px) {
            .chart-box.col-3,
            .chart-box.col-4,
            .chart-box.col-6 {
                grid-column: span 12;
            }

            .filter-compact {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }

            .top-header h1 {
                font-size: 16px;
            }

            .compact-table th,
            .compact-table td {
                padding: 8px;
                font-size: 11px;
            }
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
</head>
<body>
    <!-- Top Header -->
    <div class="top-header">
        <h1>Dashboard Preventif Perawatan Armada</h1>
        
        <div class="header-actions">
            <div class="filter-compact">
                <select onchange="filterData()">
                    <option>Februari 2026</option>
                    <option>Januari 2026</option>
                </select>
                <select onchange="filterData()">
                    <option>Semua Bidang</option>
                    <option>pergudangan</option>
                    <option>pergudangan</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Dashboard Content -->
    <div class="dashboard-content">
        <!-- Alert -->
        <div class="alert-banner">
            <span>⚠️</span>
            <strong>3 Kendaraan Urgent:</strong> WB-015, DT-019, EX-008 perlu perawatan segera
        </div>

        <div class="dashboard-grid">
            <!-- Stats Cards -->
            <div class="stats-row">
                <div class="stat-card" style="--color: #3b82f6;">
                    <h3>Total Armada</h3>
                    <div class="value">45</div>
                    <div class="detail">Aktif</div>
                </div>
                <div class="stat-card" style="--color: #10b981;">
                    <h3>Selesai</h3>
                    <div class="value">18</div>
                    <div class="detail">↑ 12%</div>
                </div>
                <div class="stat-card" style="--color: #f59e0b;">
                    <h3>Proses</h3>
                    <div class="value">6</div>
                    <div class="detail">25%</div>
                </div>
                <div class="stat-card" style="--color: #ef4444;">
                    <h3>Total Biaya</h3>
                    <div class="value">45.8M</div>
                    <div class="detail">Feb 2026</div>
                </div>
                <div class="stat-card" style="--color: #8b5cf6;">
                    <h3>Terjadwal</h3>
                    <div class="value">8</div>
                    <div class="detail">7 hari</div>
                </div>
                <div class="stat-card" style="--color: #06b6d4;">
                    <h3>Rata-rata</h3>
                    <div class="value">1.9M</div>
                    <div class="detail">/unit</div>
                </div>
            </div>

            <!-- Charts Row 1 -->
            <div class="chart-box col-3">
                <h2>📊 Status Perawatan</h2>
                <div class="chart-container">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>

            <div class="chart-box col-3">
                <h2>🏢 Per Bidang</h2>
                <div class="chart-container">
                    <canvas id="bidangChart"></canvas>
                </div>
            </div>

            <div class="chart-box col-3">
                <h2>🚗 Jenis Kendaraan</h2>
                <div class="chart-container">
                    <canvas id="vehicleChart"></canvas>
                </div>
            </div>

            <div class="chart-box col-3">
                <h2>🔧 Top Perawatan</h2>
                <div class="chart-container">
                    <canvas id="maintenanceChart"></canvas>
                </div>
            </div>

            <!-- Charts Row 2 -->
            <div class="chart-box col-6">
                <h2>📈 Trend Biaya 6 Bulan</h2>
                <div class="chart-container">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>

            <div class="chart-box col-6">
                <h2>💰 Biaya per Bidang</h2>
                <div class="chart-container">
                    <canvas id="costChart"></canvas>
                </div>
            </div>

            <!-- Table -->
            <div class="table-box">
                <h2>📋 Jadwal Perawatan Terbaru</h2>
                <div class="table-scroll">
                    <table class="compact-table">
                        <thead>
                            <tr>
                                <th>Unit</th>
                                <th>Kendaraan</th>
                                <th>Bidang</th>
                                <th>Perawatan</th>
                                <th>Tanggal</th>
                                <th>Biaya</th>
                                <th>Status</th>
                                <th>Progress</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>WB-015</strong></td>
                                <td>Wing Box</td>
                                <td><span class="badge green">pergudangan</span></td>
                                <td>Ganti Oli + Tune Up</td>
                                <td>15 Feb 26</td>
                                <td>Rp 2.35M</td>
                                <td><span class="badge red">Urgent</span></td>
                                <td>0%</td>
                            </tr>
                            <tr>
                                <td><strong>DT-001</strong></td>
                                <td>Dump Truck</td>
                                <td><span class="badge orange">AB Wil 1</span></td>
                                <td>Ganti Oli Mesin</td>
                                <td>08 Feb 26</td>
                                <td>Rp 2.85M</td>
                                <td><span class="badge blue">Terjadwal</span></td>
                                <td>25%</td>
                            </tr>
                            <tr>
                                <td><strong>EX-012</strong></td>
                                <td>Excavator</td>
                                <td><span class="badge red">AB Wil 2</span></td>
                                <td>Service Hidrolik</td>
                                <td>07 Feb 26</td>
                                <td>Rp 8.50M</td>
                                <td><span class="badge orange">Proses</span></td>
                                <td>60%</td>
                            </tr>
                            <tr>
                                <td><strong>FL-023</strong></td>
                                <td>Forklift</td>
                                <td><span class="badge purple">Pergudangan</span></td>
                                <td>Penggantian Ban</td>
                                <td>06 Feb 26</td>
                                <td>Rp 3.20M</td>
                                <td><span class="badge green">Selesai</span></td>
                                <td>100%</td>
                            </tr>
                            <tr>
                                <td><strong>TR-005</strong></td>
                                <td>Tronton</td>
                                <td><span class="badge blue">pergudangan</span></td>
                                <td>Tune Up Lengkap</td>
                                <td>05 Feb 26</td>
                                <td>Rp 4.75M</td>
                                <td><span class="badge green">Selesai</span></td>
                                <td>100%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Filter
        function filterData() {
            console.log('Filter applied');
        }

        // Chart Configurations
        const chartOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false,
                    position: 'bottom',
                    labels: { font: { size: 10 } }
                }
            }
        };

        // Chart 1: Status
        new Chart(document.getElementById('statusChart'), {
            type: 'doughnut',
            data: {
                labels: ['Selesai', 'Proses', 'Terjadwal', 'Urgent'],
                datasets: [{
                    data: [18, 6, 8, 3],
                    backgroundColor: ['#10b981', '#f59e0b', '#3b82f6', '#ef4444']
                }]
            },
            options: { ...chartOptions, plugins: { legend: { display: true } } }
        });

        // Chart 2: Bidang
        new Chart(document.getElementById('bidangChart'), {
            type: 'pie',
            data: {
                labels: ['Ang Dalam', 'Ang Luar', 'AB1', 'AB2', 'AB3', 'Gudang'],
                datasets: [{
                    data: [8, 10, 9, 7, 6, 5],
                    backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4']
                }]
            },
            options: { ...chartOptions, plugins: { legend: { display: true } } }
        });

        // Chart 3: Vehicle
        new Chart(document.getElementById('vehicleChart'), {
            type: 'doughnut',
            data: {
                labels: ['Dump Truck', 'Excavator', 'Tronton', 'Forklift', 'Lainnya'],
                datasets: [{
                    data: [12, 6, 8, 5, 14],
                    backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6']
                }]
            },
            options: { ...chartOptions, plugins: { legend: { display: true } } }
        });

        // Chart 4: Maintenance
        new Chart(document.getElementById('maintenanceChart'), {
            type: 'bar',
            data: {
                labels: ['Ganti Oli', 'Hidrolik', 'Tune Up', 'Ban', 'Rem'],
                datasets: [{
                    data: [15, 8, 9, 6, 5],
                    backgroundColor: '#3b82f6'
                }]
            },
            options: {
                ...chartOptions,
                indexAxis: 'y',
                scales: {
                    x: { display: false },
                    y: { ticks: { font: { size: 10 } } }
                }
            }
        });

        // Chart 5: Trend
        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: {
                labels: ['Sep', 'Okt', 'Nov', 'Des', 'Jan', 'Feb'],
                datasets: [{
                    label: 'Biaya (Juta)',
                    data: [38.5, 42.3, 39.8, 45.2, 41.7, 45.8],
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                ...chartOptions,
                plugins: { legend: { display: true } },
                scales: {
                    y: {
                        ticks: {
                            callback: value => value + 'M',
                            font: { size: 10 }
                        }
                    },
                    x: { ticks: { font: { size: 10 } } }
                }
            }
        });

        // Chart 6: Cost
        new Chart(document.getElementById('costChart'), {
            type: 'bar',
            data: {
                labels: ['Ang Dalam', 'Ang Luar', 'AB1', 'AB2', 'AB3', 'Gudang'],
                datasets: [{
                    data: [8.2, 10.85, 13.75, 15.2, 18.35, 6.95],
                    backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4']
                }]
            },
            options: {
                ...chartOptions,
                scales: {
                    y: {
                        ticks: {
                            callback: value => value + 'M',
                            font: { size: 10 }
                        }
                    },
                    x: { ticks: { font: { size: 10 } } }
                }
            }
        });
    </script>
</body>
</html>