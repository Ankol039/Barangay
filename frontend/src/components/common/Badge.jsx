import PropTypes from 'prop-types'
export default function Badge({ variant, children, className = '', ...props }) {
  return <span {...props} className={`badge${variant ? ` badge-${variant}` : ''}${className ? ` ${className}` : ''}`}>{children}</span>
}

if (import.meta.env.DEV) {
  Badge.propTypes = {
    variant: PropTypes.string.isRequired,
    children: PropTypes.node,
    className: PropTypes.string,
  }
}
