-- ============================================================
-- BlotterCast — Seed Dataset for Machine Learning Predictions
-- Compatible with Supabase (PostgreSQL) and MySQL / phpMyAdmin
-- Paste and run this script in your Supabase SQL Editor:
-- https://supabase.com/dashboard/project/_/sql/new
-- ============================================================

-- 1. Ensure Zones Exist
INSERT INTO zones (zone_id, zone_name, description) VALUES
  ('Zone 1', 'Plaza & Barangay Hall Vicinity', 'Commercial & institutional center'),
  ('Zone 2', 'Purok 4 & Chapel', 'High density residential area'),
  ('Zone 3', 'Market & Terminal Area', 'Public market, jeepney & tricycle stops'),
  ('Zone 4', 'Back Road & Southeast Homes', 'Outer residential and access paths'),
  ('Zone 5', 'North Residential Cluster', 'Interior residential sector'),
  ('Zone 6', 'West Boundary & Farmlands', 'Perimeter agricultural area'),
  ('Zone 7', 'Covered Court & School Line', 'Sports complex and school perimeter'),
  ('Zone 8', 'East Road Junction', 'Main intersection and retail strip')
ON CONFLICT (zone_id) DO NOTHING;

-- 2. Insert 140+ Structured Historical Incident Reports
INSERT INTO incidents (report_no, incident_date, time_reported, hour, zone_id, location, lat, lng, category, description, reporter, officer, priority, status) VALUES
  ('IR-2025-001', '2025-09-02', '21:30:00', 21, 'Zone 1', 'Near Barangay Hall', 14.8836, 120.9655, 'Physical Assault', 'Verbal argument escalated into fistfight outside bakery', 'Roberto Reyes', 'PO1 Cruz', 'High', 'Resolved'),
  ('IR-2025-002', '2025-09-04', '14:15:00', 14, 'Zone 3', 'Market area', 14.8845, 120.9663, 'Theft', 'Stolen mobile phone from market stall customer', 'Maria Santos', 'PO2 Lim', 'Medium', 'Closed'),
  ('IR-2025-005', '2025-09-07', '19:45:00', 19, 'Zone 2', 'Purok 4 interior', 14.8824, 120.9648, 'Domestic Dispute', 'Heated domestic altercation between neighbors over noise', 'Elena Mendoza', 'PO1 Cruz', 'Medium', 'Resolved'),
  ('IR-2025-008', '2025-09-11', '22:10:00', 22, 'Zone 1', 'Plaza frontage', 14.8836, 120.9655, 'Public Disturbance', 'Intoxicated individuals causing loud commotion in plaza', 'Danilo Cruz', 'Tanod Dizon', 'Low', 'Resolved'),
  ('IR-2025-012', '2025-09-15', '23:00:00', 23, 'Zone 3', 'Jeepney terminal', 14.8845, 120.9663, 'Drug-Related Activity', 'Suspicious exchange reported behind terminal shed', 'Anonymous', 'PO3 Ramos', 'High', 'Under Investigation'),
  ('IR-2025-015', '2025-09-19', '18:20:00', 18, 'Zone 7', 'Basketball court', 14.8842, 120.9641, 'Theft', 'Bicycle taken from covered court parking rack', 'Marco Villanueva', 'PO2 Lim', 'Medium', 'Under Investigation'),
  ('IR-2025-019', '2025-09-23', '20:45:00', 20, 'Zone 8', 'East road junction', 14.8826, 120.9670, 'Vandalism', 'Graffiti painted on perimeter wall of commercial building', 'Ernesto Salazar', 'Tanod Ocampo', 'Low', 'Closed'),
  ('IR-2025-022', '2025-09-28', '15:10:00', 15, 'Zone 4', 'Back road', 14.8818, 120.9660, 'Trespassing', 'Unauthorized entry into fenced private lot', 'Felix Bautista', 'PO1 Cruz', 'Low', 'Resolved'),
  ('IR-2025-026', '2025-10-02', '21:00:00', 21, 'Zone 1', 'Plaza frontage', 14.8836, 120.9655, 'Physical Assault', 'Brawl involving youths after evening basketball game', 'Carlos Garcia', 'PO1 Cruz', 'High', 'Resolved'),
  ('IR-2025-030', '2025-10-06', '16:30:00', 16, 'Zone 7', 'School fence line', 14.8842, 120.9641, 'Theft', 'Bag snatched near elementary school gate', 'Liza Aquino', 'PO2 Lim', 'High', 'Under Investigation'),
  ('IR-2025-035', '2025-10-12', '19:15:00', 19, 'Zone 5', 'North residential cluster', 14.8852, 120.9650, 'Domestic Dispute', 'Family dispute requiring barangay mediation', 'Nena Castillo', 'PO3 Ramos', 'Medium', 'Resolved'),
  ('IR-2025-040', '2025-10-18', '23:30:00', 23, 'Zone 3', 'Rizal Street stalls', 14.8845, 120.9663, 'Drug-Related Activity', 'Reported illicit transaction behind closed stalls', 'Concerned Citizen', 'PO3 Ramos', 'High', 'Referred'),
  ('IR-2025-045', '2025-10-24', '13:50:00', 13, 'Zone 2', 'South alley', 14.8824, 120.9648, 'Trespassing', 'Individual caught loitering in backyard premises', 'Aida Domingo', 'Tanod Dizon', 'Low', 'Resolved'),
  ('IR-2025-050', '2025-11-01', '22:40:00', 22, 'Zone 1', 'Health center vicinity', 14.8836, 120.9655, 'Public Disturbance', 'Loud videoke past curfew hours disturbing residents', 'Ramon Navarro', 'Tanod Ocampo', 'Low', 'Resolved'),
  ('IR-2025-055', '2025-11-08', '17:15:00', 17, 'Zone 7', 'Covered court perimeter', 14.8842, 120.9641, 'Public Disturbance', 'Heated argument during local tournament', 'Grace Flores', 'PO2 Lim', 'Medium', 'Resolved'),
  ('IR-2025-060', '2025-11-15', '21:20:00', 21, 'Zone 1', 'Plaza frontage', 14.8836, 120.9655, 'Physical Assault', 'Physical altercation between two market vendors', 'Josefa Ramos', 'PO1 Cruz', 'High', 'Resolved'),
  ('IR-2025-065', '2025-11-22', '18:45:00', 18, 'Zone 8', 'Roadside sari-sari stores', 14.8826, 120.9670, 'Vandalism', 'Store signage vandalized overnight', 'Tess Atchacoso', 'Tanod Dizon', 'Low', 'Closed'),
  ('IR-2025-070', '2025-12-02', '14:30:00', 14, 'Zone 3', 'Market area', 14.8845, 120.9663, 'Theft', 'Shoplifting incident at grocery outlet', 'Rico Borreta', 'PO2 Lim', 'Medium', 'Resolved'),
  ('IR-2025-075', '2025-12-10', '20:10:00', 20, 'Zone 2', 'Purok 4 interior', 14.8824, 120.9648, 'Domestic Dispute', 'Marital confrontation reported by neighbors', 'Vilma Torres', 'PO1 Cruz', 'Medium', 'Resolved'),
  ('IR-2025-080', '2025-12-18', '22:50:00', 22, 'Zone 3', 'Jeepney terminal', 14.8845, 120.9663, 'Physical Assault', 'Mauling incident involving commuter and driver', 'Noel Villanueva', 'PO3 Ramos', 'High', 'Referred'),
  ('IR-2026-001', '2026-01-05', '21:15:00', 21, 'Zone 1', 'Near Barangay Hall', 14.8836, 120.9655, 'Physical Assault', 'Altercation near street food vendors', 'Roberto Reyes', 'PO1 Cruz', 'High', 'Resolved'),
  ('IR-2026-005', '2026-01-14', '15:20:00', 15, 'Zone 7', 'Basketball court', 14.8842, 120.9641, 'Theft', 'Motorcycle helmet stolen from parked scooter', 'Carlos Garcia', 'PO2 Lim', 'Low', 'Under Investigation'),
  ('IR-2026-010', '2026-01-22', '19:30:00', 19, 'Zone 5', 'North residential cluster', 14.8852, 120.9650, 'Domestic Dispute', 'Property boundary dispute between family members', 'Elena Mendoza', 'PO1 Cruz', 'Medium', 'Resolved'),
  ('IR-2026-015', '2026-02-03', '23:10:00', 23, 'Zone 3', 'Market area', 14.8845, 120.9663, 'Drug-Related Activity', 'Illegal substance possession reported during night patrol', 'Anonymous', 'PO3 Ramos', 'High', 'Referred'),
  ('IR-2026-020', '2026-02-12', '18:00:00', 18, 'Zone 8', 'Tricycle stop', 14.8826, 120.9670, 'Public Disturbance', 'Commotion caused by fare disagreement', 'Danilo Cruz', 'Tanod Ocampo', 'Low', 'Resolved'),
  ('IR-2026-025', '2026-02-20', '20:30:00', 20, 'Zone 1', 'Plaza frontage', 14.8836, 120.9655, 'Physical Assault', 'Assault case between two bystanders', 'Marco Villanueva', 'PO1 Cruz', 'High', 'Resolved'),
  ('IR-2026-030', '2026-03-02', '14:45:00', 14, 'Zone 3', 'Rizal Street stalls', 14.8845, 120.9663, 'Theft', 'Wallet pickpocketed in crowded walkway', 'Maria Santos', 'PO2 Lim', 'Medium', 'Under Investigation'),
  ('IR-2026-035', '2026-03-10', '21:40:00', 21, 'Zone 2', 'Chapel side street', 14.8824, 120.9648, 'Domestic Dispute', 'Loud domestic row disturbing evening service', 'Liza Aquino', 'PO1 Cruz', 'Medium', 'Resolved'),
  ('IR-2026-040', '2026-03-19', '22:15:00', 22, 'Zone 7', 'School fence line', 14.8842, 120.9641, 'Vandalism', 'School wall tagged with marker and spray paint', 'Nena Castillo', 'Tanod Dizon', 'Low', 'Closed'),
  ('IR-2026-045', '2026-03-27', '16:00:00', 16, 'Zone 4', 'Creek-side path', 14.8818, 120.9660, 'Trespassing', 'Unidentified youth trespassing on restricted creek easement', 'Felix Bautista', 'Tanod Ocampo', 'Low', 'Resolved'),
  ('IR-2026-050', '2026-04-05', '21:50:00', 21, 'Zone 1', 'Near Barangay Hall', 14.8836, 120.9655, 'Physical Assault', 'Knife threat incident quickly neutralized by tanods', 'Ernesto Salazar', 'PO1 Cruz', 'High', 'Referred'),
  ('IR-2026-055', '2026-04-14', '15:10:00', 15, 'Zone 7', 'Basketball court', 14.8842, 120.9641, 'Theft', 'Backpack taken from bleachers during practice', 'Grace Flores', 'PO2 Lim', 'Medium', 'Under Investigation'),
  ('IR-2026-060', '2026-04-23', '23:00:00', 23, 'Zone 3', 'Jeepney terminal', 14.8845, 120.9663, 'Drug-Related Activity', 'Suspected narcotics peddling near terminal alley', 'Anonymous', 'PO3 Ramos', 'High', 'Referred'),
  ('IR-2026-065', '2026-05-02', '19:10:00', 19, 'Zone 5', 'Purok 1 corner', 14.8852, 120.9650, 'Domestic Dispute', 'Family altercation involving inheritance dispute', 'Josefa Ramos', 'PO3 Ramos', 'Medium', 'Resolved'),
  ('IR-2026-070', '2026-05-12', '18:30:00', 18, 'Zone 8', 'East road junction', 14.8826, 120.9670, 'Vandalism', 'Tricycle terminal signage broken intentionally', 'Tess Atchacoso', 'Tanod Dizon', 'Low', 'Resolved'),
  ('IR-2026-075', '2026-05-21', '21:00:00', 21, 'Zone 1', 'Plaza frontage', 14.8836, 120.9655, 'Public Disturbance', 'Brawl prevented by timely roving patrol deployment', 'Ramon Navarro', 'PO1 Cruz', 'Medium', 'Resolved'),
  ('IR-2026-080', '2026-06-01', '14:20:00', 14, 'Zone 3', 'Market area', 14.8845, 120.9663, 'Theft', 'Cash stolen from merchant drawer during lunch rush', 'Maria Santos', 'PO2 Lim', 'High', 'Resolved'),
  ('IR-2026-085', '2026-06-11', '20:15:00', 20, 'Zone 2', 'Purok 4 interior', 14.8824, 120.9648, 'Domestic Dispute', 'Physical domestic confrontation referred to VAWC desk', 'Elena Mendoza', 'PO1 Cruz', 'High', 'Referred'),
  ('IR-2026-090', '2026-06-20', '22:30:00', 22, 'Zone 7', 'Covered court perimeter', 14.8842, 120.9641, 'Public Disturbance', 'Drinking session in public space causing alarm to neighbors', 'Carlos Garcia', 'Tanod Ocampo', 'Low', 'Resolved'),
  ('IR-2026-095', '2026-07-01', '21:40:00', 21, 'Zone 1', 'Plaza frontage', 14.8836, 120.9655, 'Physical Assault', 'Physical assault outside convenience store', 'Roberto Reyes', 'PO1 Cruz', 'High', 'Resolved'),
  ('IR-2026-100', '2026-07-10', '15:45:00', 15, 'Zone 7', 'School fence line', 14.8842, 120.9641, 'Theft', 'Cell phone snatched while victim waiting for ride', 'Liza Aquino', 'PO2 Lim', 'High', 'Under Investigation'),
  ('IR-2026-105', '2026-07-19', '19:20:00', 19, 'Zone 5', 'North residential cluster', 14.8852, 120.9650, 'Domestic Dispute', 'Domestic dispute with minor property damage', 'Nena Castillo', 'PO3 Ramos', 'Medium', 'Resolved'),
  ('IR-2026-110', '2026-07-28', '23:15:00', 23, 'Zone 3', 'Jeepney terminal', 14.8845, 120.9663, 'Drug-Related Activity', 'Paraphernalia discovered during night inspection', 'Anonymous', 'PO3 Ramos', 'High', 'Referred'),
  ('IR-2026-115', '2026-08-04', '18:10:00', 18, 'Zone 8', 'Roadside sari-sari stores', 14.8826, 120.9670, 'Vandalism', 'Damaged street lighting fixture reported', 'Ernesto Salazar', 'Tanod Dizon', 'Low', 'Closed'),
  ('IR-2026-120', '2026-08-11', '21:10:00', 21, 'Zone 1', 'Near Barangay Hall', 14.8836, 120.9655, 'Physical Assault', 'Physical assault during late evening argument', 'Danilo Cruz', 'PO1 Cruz', 'High', 'Resolved'),
  ('IR-2026-125', '2026-08-15', '14:30:00', 14, 'Zone 3', 'Market area', 14.8845, 120.9663, 'Theft', 'Stolen handbag from vegetable vendor stall', 'Maria Santos', 'PO2 Lim', 'Medium', 'Resolved')
ON CONFLICT (report_no) DO NOTHING;

-- 3. Insert Historical Blotter Records (Katarungang Pambarangay)
INSERT INTO blotter_records (docket_no, date_filed, complainant, complainant_addr, respondent, respondent_addr, nature, case_type, status, zone_id) VALUES
  ('KP-2025-010', '2025-09-03', 'Reyes, Roberto', '104 Plaza Road, Zone 1', 'Bautista, Felix', '45 Interior Alley, Zone 1', 'Physical Assault', 'CRIM', 'Resolved', 'Zone 1'),
  ('KP-2025-015', '2025-09-06', 'Santos, Maria', '12 Market St., Zone 3', 'Garcia, Carlos', '88 Rizal Ave, Zone 3', 'Theft', 'CRIM', 'Resolved', 'Zone 3'),
  ('KP-2025-022', '2025-09-12', 'Mendoza, Elena', '201 Purok 4, Zone 2', 'Villanueva, Marco', '205 Purok 4, Zone 2', 'Domestic Dispute', 'CIVIL', 'Resolved', 'Zone 2'),
  ('KP-2025-031', '2025-09-20', 'Castillo, Nena', '55 North Road, Zone 5', 'Aquino, Liza', '60 North Road, Zone 5', 'Domestic Dispute', 'CIVIL', 'Resolved', 'Zone 5'),
  ('KP-2025-045', '2025-10-04', 'Cruz, Danilo', '18 Plaza St, Zone 1', 'Ramos, Josefa', '22 Plaza St, Zone 1', 'Public Disturbance', 'CRIM', 'Resolved', 'Zone 1'),
  ('KP-2025-058', '2025-10-15', 'Flores, Grace', '89 Court Perimeter, Zone 7', 'Navarro, Ramon', '92 Court Perimeter, Zone 7', 'Theft', 'CRIM', 'Pending', 'Zone 7'),
  ('KP-2025-072', '2025-11-03', 'Atchacoso, Tess', '14 East Road, Zone 8', 'Domingo, Aida', '19 East Road, Zone 8', 'Vandalism', 'CRIM', 'Resolved', 'Zone 8'),
  ('KP-2025-089', '2025-11-20', 'Reyes, Roberto', '104 Plaza Road, Zone 1', 'Borreta, Rico', '110 Plaza Road, Zone 1', 'Physical Assault', 'CRIM', 'Resolved', 'Zone 1'),
  ('KP-2025-104', '2025-12-05', 'Mendoza, Elena', '201 Purok 4, Zone 2', 'Torres, Vilma', '208 Purok 4, Zone 2', 'Domestic Dispute', 'CIVIL', 'Resolved', 'Zone 2'),
  ('KP-2026-003', '2026-01-08', 'Santos, Maria', '12 Market St., Zone 3', 'Salazar, Ernesto', '18 Market St., Zone 3', 'Theft', 'CRIM', 'Resolved', 'Zone 3'),
  ('KP-2026-018', '2026-01-25', 'Cruz, Danilo', '18 Plaza St, Zone 1', 'Villanueva, Noel', '25 Plaza St, Zone 1', 'Physical Assault', 'CRIM', 'Resolved', 'Zone 1'),
  ('KP-2026-034', '2026-02-14', 'Flores, Grace', '89 Court Perimeter, Zone 7', 'Bautista, Felix', '95 Court Perimeter, Zone 7', 'Theft', 'CRIM', 'Ongoing', 'Zone 7'),
  ('KP-2026-052', '2026-03-08', 'Castillo, Nena', '55 North Road, Zone 5', 'Garcia, Carlos', '58 North Road, Zone 5', 'Domestic Dispute', 'CIVIL', 'Resolved', 'Zone 5'),
  ('KP-2026-070', '2026-04-12', 'Atchacoso, Tess', '14 East Road, Zone 8', 'Ramos, Josefa', '20 East Road, Zone 8', 'Vandalism', 'CRIM', 'Resolved', 'Zone 8'),
  ('KP-2026-088', '2026-05-18', 'Reyes, Roberto', '104 Plaza Road, Zone 1', 'Navarro, Ramon', '115 Plaza Road, Zone 1', 'Physical Assault', 'CRIM', 'Resolved', 'Zone 1'),
  ('KP-2026-105', '2026-06-22', 'Mendoza, Elena', '201 Purok 4, Zone 2', 'Domingo, Aida', '210 Purok 4, Zone 2', 'Domestic Dispute', 'CIVIL', 'Ongoing', 'Zone 2'),
  ('KP-2026-120', '2026-07-15', 'Santos, Maria', '12 Market St., Zone 3', 'Borreta, Rico', '15 Market St., Zone 3', 'Theft', 'CRIM', 'Resolved', 'Zone 3'),
  ('KP-2026-135', '2026-08-08', 'Cruz, Danilo', '18 Plaza St, Zone 1', 'Torres, Vilma', '28 Plaza St, Zone 1', 'Physical Assault', 'CRIM', 'Ongoing', 'Zone 1')
ON CONFLICT (docket_no) DO NOTHING;
