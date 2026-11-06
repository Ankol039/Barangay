import PropTypes from 'prop-types'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import Modal from '../../components/common/Modal.jsx'
import Alert from '../../components/common/Alert.jsx'
import useDirtyWarning from '../../hooks/useDirtyWarning.js'

export default function DemoModal({ open, onClose, keepOnClose = false }) {
  const guard = useDirtyWarning({ enabled: open })
  const [error, setError] = useState('')
  const [name, setName] = useState('')
  return <Modal id={keepOnClose ? 'keep-demo-modal' : 'demo-modal'} title="Request Change" open={open} onClose={onClose}
    keepOnClose={keepOnClose} beforeClose={guard.confirmDiscard}
    onReset={() => { setName(''); setError(''); guard.clear() }}>
    {error && <Alert type="error">{error}</Alert>}
    <form {...guard.formProps} onSubmit={(event) => {
      event.preventDefault()
      guard.clear()
      setError('Nothing has changed yet. Edit a name or address field before sending the request.')
    }}>
      <input type="hidden" name="action" value="request_name_address" />
      <label htmlFor={`${keepOnClose}-name`}>First Name</label>
      <input id={`${keepOnClose}-name`} name="first_name" value={name} onChange={(event) => setName(event.target.value)} />
      <label htmlFor={`${keepOnClose}-note`}>Reason</label><textarea id={`${keepOnClose}-note`} name="reason" />
      <label htmlFor={`${keepOnClose}-select`}>Courtesy Title</label><select id={`${keepOnClose}-select`} name="courtesy_title"><option value="">-- Choose --</option><option>Mr.</option><option>Ms.</option></select>
      <label><input type="checkbox" name="checked_default" defaultChecked /> Default checkbox</label>
      <Link className="btn btn-outline" to="/dev/components?portal=resident">My Profile</Link>
      <div className="modal-form-actions"><button className="btn" type="submit">Send Request</button>
        <button className="btn btn-secondary" type="button" data-modal-close="">Cancel</button></div>
    </form>
  </Modal>
}

if (import.meta.env.DEV) {
  DemoModal.propTypes = {
    open: PropTypes.bool.isRequired,
    onClose: PropTypes.func,
    keepOnClose: PropTypes.bool,
  }
}
