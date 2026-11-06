export const demoUser = {
  account_type: 'staff', role: 'admin', display_name: 'React UI Fixture', initial: 'R',
  portal_label: 'Admin Portal', photo: '', is_first_login: false, profile_completed: true,
  show_sidebar: true, pending_deletions: 3, archived_accounts: 2,
}
export const surveys = [
  { id: 1, title: 'Health Center Services', start_date: '2026-11-01', end_date: '2026-11-30', status: 'active', response_count: 12 },
  { id: 2, title: 'Community Wellness', start_date: '2026-10-01', end_date: '2026-10-31', status: 'inactive', response_count: 4 },
].map((row) => ({ ...row, searchText: `${row.title} ${row.start_date} ${row.end_date} ${row.status}` }))
export const residentFilters = [
  ['all', 'All'], ['permanent', 'Permanent'], ['temporary', 'Temporary'],
  ['pending-login', 'Pending Login'], ['deactivated', 'Deactivated'],
].map(([value, label]) => ({ value, label }))
export const residents = [
  { id: 1, number: 'R-2099-0001', name: 'Demo Permanent', filter: 'permanent', residency: 'permanent' },
  { id: 2, number: 'R-2099-0002', name: 'Demo Temporary', filter: 'temporary pending-login', residency: 'temporary' },
  { id: 3, number: 'R-2099-0003', name: 'Demo Deactivated', filter: 'temporary deactivated', residency: 'temporary', className: 'row-inactive' },
].map((row) => ({ ...row, searchText: `${row.number} ${row.name}` }))
